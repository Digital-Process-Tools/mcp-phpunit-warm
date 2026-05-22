<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\TextUI\Application;

/**
 * Runs PHPUnit in-process across multiple calls.
 *
 * PHPUnit 10/11/12 uses static singletons (EventFacade, Registry, OutputFacade, etc.)
 * that are sealed after the first run. We reset them via Reflection between calls.
 * The autoloader stays warm — class files are already parsed and opcode-cached.
 *
 * Results are collected in-memory via InMemorySubscriber registered on the EventFacade
 * before each run. No temp file round-trip; no --log-junit overhead.
 */
final class PhpunitRunner
{
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
     * Optionally pre-warm the PHPUnit bootstrap by running a no-op discovery pass.
     *
     * Calling this once at daemon startup pays the bootstrap cost before the first
     * real test call arrives, so warm calls benefit from a pre-loaded framework.
     *
     * @param list<string> $baseArgv Base phpunit argv (binary name + optional --configuration).
     */
    public function prewarm(array $baseArgv): void
    {
        if ($this->warm) {
            return;
        }

        // --list-tests triggers bootstrap + autoload without running any test.
        $argv = array_merge($baseArgv, ['--list-tests', '--no-output', '--no-coverage']);

        ob_start();
        try {
            $app = new Application();
            $app->run($argv);
        } catch (\Throwable) {
            // Ignore: list-tests may exit non-zero if no tests match yet — that's fine.
        } finally {
            ob_end_clean();
        }

        // Reset singletons so the first real run starts clean, but keep $warm = false
        // so the caller still gets warm_boot = false on the first real call.
        $this->resetStaticSingletons();
    }

    /**
     * Run PHPUnit. Returns exit code, structured JSON output, and warm_boot flag.
     *
     * @param list<string> $argv PHPUnit CLI args including binary name as $argv[0].
     *   E.g. ['phpunit', '--configuration', '/path/phpunit.xml', 'tests/FooTest.php']
     * @return array{exit_code: int, output: string, warm_boot: bool}
     */
    public function run(array $argv): array
    {
        $warmBoot = $this->warm;

        if ($warmBoot) {
            $this->resetStaticSingletons();
        }

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

        $this->warm = true;

        return [
            'exit_code' => $exitCode,
            'output'    => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'warm_boot' => $warmBoot,
        ];
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
