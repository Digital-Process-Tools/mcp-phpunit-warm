<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\TextUI\Application;

/**
 * Runs PHPUnit warm — but each test run happens in a short-lived forked child.
 *
 * THE STALENESS PROBLEM. A long-lived PHP process cannot reload a class once it
 * is autoloaded — PHP forbids redeclaration. So a naive in-process warm runner
 * keeps executing the FIRST version of every source/test class it ever loaded:
 * edit `Foo.php` on disk, the warm process still runs the stale `Foo` and reports
 * a result that no longer matches the file. (Issue: claude-supertool#265.)
 *
 * THE FIX — fork per call. The long-lived PARENT bootstraps only the PHPUnit
 * framework (+ the project's phpunit.xml bootstrap) and never loads a single user
 * source/test class. Each {@see run()} call forks; the CHILD autoloads the test +
 * source classes fresh from disk, runs them, ships the result back over a socket,
 * and dies. Because the child is a fresh fork of the framework-warm-but-app-clean
 * parent, it inherits the compiled PHPUnit framework via copy-on-write (warm) yet
 * parses the user's classes anew every time (fresh). Warm and correct.
 *
 * Results are collected in-memory via InMemorySubscriber registered on the
 * EventFacade before each run. No temp file round-trip; no --log-junit overhead.
 */
final class PhpunitRunner
{
    /**
     * Upper bound on how long the parent waits for a forked child's result before
     * killing it. Guards the single-client daemon against a wedged test (infinite
     * loop) freezing it. Generous: real suites finish well under this; PHPUnit's own
     * enforceTimeLimit usually trips first.
     */
    private const CHILD_READ_TIMEOUT_S = 600;

    private static ?self $shared = null;

    private bool $warm = false;
    private InMemorySubscriber $subscriber;

    public function __construct()
    {
        $this->subscriber = new InMemorySubscriber();
    }

    /**
     * Shared instance used by PhpunitTool when the bin script pre-warms.
     * The bin script calls setShared() after prewarm(); PhpunitTool calls instance().
     */
    public static function setShared(self $runner): void
    {
        self::$shared = $runner;
    }

    public static function instance(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }

        return self::$shared;
    }

    public function isWarm(): bool
    {
        return $this->warm;
    }

    /**
     * Warm the PHPUnit framework + the project's phpunit.xml bootstrap WITHOUT
     * loading any user test/source class — those must stay unloaded in the parent
     * so every forked {@see run()} child parses them fresh from disk.
     *
     * We run a single bundled throwaway probe test through the real config: the
     * config's bootstrap fires (autoloader registered → warm, copy-on-write
     * inherited by children) and the full framework run path loads, but the only
     * *test* class pulled in is our probe — which is never edited. `--no-output`
     * keeps the printer silent so nothing reaches php://stdout (the old
     * `--list-tests` prewarm corrupted the MCP stdio transport that way).
     *
     * @param list<string> $baseArgv Base phpunit argv (binary name + optional --configuration).
     */
    public function prewarm(array $baseArgv): void
    {
        if ($this->warm) {
            return;
        }

        $argv = array_merge($baseArgv, [self::prewarmProbePath()]);

        try {
            $this->runInProcess($argv);
        } catch (\Throwable) {
            // Probe failures are irrelevant — we only want the side effect of a warm boot.
        }

        // Leave warm=false so the first real call still reports warm_boot=false, and
        // reset singletons so that first real run starts from a clean framework state.
        $this->resetStaticSingletons();
    }

    /**
     * Run PHPUnit in a forked child so the test sees the current on-disk classes.
     *
     * @param list<string> $argv PHPUnit CLI args including binary name as $argv[0].
     *   E.g. ['phpunit', '--configuration', '/path/phpunit.xml', 'tests/FooTest.php']
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv): array
    {
        $warmBoot   = $this->warm;
        $this->warm = true;

        // No process control (e.g. Windows, pcntl disabled): fall back to in-process.
        // Correctness degrades to the old stale-after-edit behaviour, but the tool
        // still works rather than failing outright.
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            return $this->runInProcess($argv) + ['warm_boot' => $warmBoot];
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            return $this->runInProcess($argv) + ['warm_boot' => $warmBoot];
        }
        [$parentSock, $childSock] = $pair;

        $pid = pcntl_fork();

        if ($pid === -1) {
            fclose($parentSock);
            fclose($childSock);

            return $this->runInProcess($argv) + ['warm_boot' => $warmBoot];
        }

        if ($pid === 0) {
            // ---- CHILD ----
            fclose($parentSock);

            try {
                $payload = $this->runInProcess($argv);
            } catch (\Throwable $exception) {
                $payload = [
                    'exit_code' => -1,
                    'output'    => $this->errorOutput($exception->getMessage()),
                ];
            }

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            fwrite($childSock, (string) $json);
            fflush($childSock);
            fclose($childSock);

            $this->terminateChild();
        }

        // ---- PARENT ----
        fclose($childSock);
        // Read BEFORE waitpid: a payload larger than the socket buffer would
        // otherwise deadlock the child's blocking write against an unread socket.
        // Bound the read so a wedged child (e.g. an infinite loop in user test code)
        // can't freeze the single-client daemon until its idle timeout.
        stream_set_timeout($parentSock, self::CHILD_READ_TIMEOUT_S);
        $raw      = stream_get_contents($parentSock);
        $timedOut = (bool) (stream_get_meta_data($parentSock)['timed_out'] ?? false);
        fclose($parentSock);

        if ($timedOut) {
            // Kill the wedged child and reap it so it doesn't become a zombie.
            if (\function_exists('posix_kill')) {
                @posix_kill($pid, \defined('SIGKILL') ? SIGKILL : 9);
            }
            pcntl_waitpid($pid, $status);

            return [
                'exit_code' => -1,
                'output'    => $this->errorOutput(
                    'phpunit child exceeded the ' . self::CHILD_READ_TIMEOUT_S . 's read timeout and was killed'
                ),
                'warm_boot' => $warmBoot,
            ];
        }

        pcntl_waitpid($pid, $status);

        $payload = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;

        if (!is_array($payload) || !isset($payload['exit_code'])) {
            // Child crashed (fatal/segfault) before shipping a result.
            $exitCode = (\function_exists('pcntl_wifexited') && pcntl_wifexited($status))
                ? pcntl_wexitstatus($status)
                : 255;

            return [
                'exit_code' => $exitCode !== 0 ? $exitCode : 255,
                'output'    => $this->errorOutput(
                    'phpunit child produced no result (fatal error or crash in the forked test process)'
                ),
                'warm_boot' => $warmBoot,
            ];
        }

        $payload['warm_boot'] = $warmBoot;

        return $payload;
    }

    /**
     * Execute PHPUnit in the current process and collect the in-memory result.
     *
     * In the fork model this runs inside the short-lived child; on a fork-less
     * platform it runs in the daemon directly (stale-prone fallback).
     *
     * @param list<string> $argv
     * @return array{exit_code: int, output: string}
     */
    private function runInProcess(array $argv): array
    {
        // Each child is a fresh fork of a framework that was bootstrapped (and
        // sealed) during prewarm, so reset the sealed singletons before running.
        $this->resetStaticSingletons();

        $this->subscriber->reset();

        // Register in-memory subscribers before Application::run() seals the EventFacade.
        foreach ($this->subscriber->subscribers() as $sub) {
            EventFacade::instance()->registerSubscriber($sub);
        }

        // --no-output: forces NullPrinter so DefaultPrinter never writes to php://stdout.
        // --no-coverage: skip coverage collection (expensive, not useful per MCP call).
        // No --log-junit: results captured in-memory by InMemorySubscriber.
        $fullArgv = array_merge($argv, ['--no-output', '--no-coverage']);

        // ob_start captures any print/echo from Application internals (e.g. crash messages).
        ob_start();
        try {
            $app      = new Application();
            $exitCode = $app->run($fullArgv);
        } finally {
            $echoed = ob_get_clean();
        }

        $result = $this->subscriber->result();

        // Append any internal echo output (crash messages, fatal errors) as a note.
        if (is_string($echoed) && $echoed !== '') {
            $result['echo'] = $echoed;
        }

        return [
            'exit_code' => $exitCode,
            'output'    => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Terminate the forked child once its result is on the wire.
     *
     * SIGKILL (not exit()) so NO shutdown handler, output-buffer flush, or
     * destructor runs: the child inherited the parent's MCP stdout pipe, and a
     * stray write from any teardown hook would corrupt the JSON-RPC stream. The
     * result socket is already flushed + closed before we get here, so the parent
     * has the payload; the abrupt death only drops the child's own resources
     * (db sockets etc.), which the OS/server cleans up on disconnect.
     */
    private function terminateChild(): never
    {
        if (\function_exists('posix_kill') && \function_exists('posix_getpid')) {
            posix_kill(posix_getpid(), \defined('SIGKILL') ? SIGKILL : 9);
        }
        // Fallback when posix is unavailable: exit() runs shutdown handlers /
        // destructors / buffer flushes, which could write to the inherited MCP stdout
        // pipe. The run is finished, so it's now safe to repoint fd 1 at /dev/null
        // first (unlike during the run, where closing it crashes PHPUnit).
        if (\defined('STDOUT')) {
            @fclose(STDOUT);
        }
        $devnull = @fopen('/dev/null', 'w');
        unset($devnull);
        exit(0);
    }

    /**
     * Build an InMemorySubscriber-shaped result JSON carrying a single error, so
     * the validator adapter renders the failure instead of choking on empty output.
     */
    private function errorOutput(string $message): string
    {
        return (string) json_encode([
            'tests'      => 0,
            'assertions' => 0,
            'failures'   => [],
            'errors'     => [[
                'class'   => self::class,
                'method'  => 'run',
                'file'    => null,
                'line'    => null,
                'message' => $message,
            ]],
            'skipped' => [],
            'time'    => 0.0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function prewarmProbePath(): string
    {
        return \dirname(__DIR__) . '/resources/PrewarmProbeTest.php';
    }

    /**
     * Reset PHPUnit's static singletons so Application::run() can be called again.
     *
     * PHPUnit seals its EventFacade after the first run. Without this reset,
     * the second call throws EventFacadeIsSealedException.
     */
    private function resetStaticSingletons(): void
    {
        $this->resetStaticProp('PHPUnit\Event\Facade', 'instance', null);
        $this->resetStaticProp('PHPUnit\TextUI\Configuration\Registry', 'instance', null);
        $this->resetStaticProp('PHPUnit\TestRunner\TestResult\Facade', 'collector', null);
        $this->resetStaticProp('PHPUnit\Runner\CodeCoverage', 'instance', null);

        // OutputFacade: printer + printers + progress flag
        $outputFacadeClass = 'PHPUnit\TextUI\Output\Facade';
        foreach (['printer', 'defaultResultPrinter', 'testDoxResultPrinter', 'summaryPrinter'] as $prop) {
            $this->resetStaticProp($outputFacadeClass, $prop, null);
        }
        $this->resetStaticProp($outputFacadeClass, 'defaultProgressPrinter', false);

        // ErrorHandler — present in PHPUnit 10, may be absent in future versions
        if (class_exists('PHPUnit\Runner\ErrorHandler', false)) {
            $this->resetStaticProp('PHPUnit\Runner\ErrorHandler', 'instance', null);
        }
    }

    private function resetStaticProp(string $class, string $property, mixed $value): void
    {
        try {
            $prop = (new \ReflectionClass($class))->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        } catch (\ReflectionException) {
            // Property renamed in a future phpunit version — skip silently.
        }
    }
}
