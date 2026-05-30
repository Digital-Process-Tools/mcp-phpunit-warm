<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Spawns bin/mcp-phpunit-warm as a subprocess, feeds JSON-RPC on stdin, asserts responses.
 * Covers the real boot path (autoload + PHPUnit Application).
 */
final class ServerStdioTest extends TestCase
{
    private static string $bin;
    private static string $fixtureDir;

    /** @var list<string> temp project dirs created per test, removed in tearDown */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tmpDirs = [];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public static function setUpBeforeClass(): void
    {
        self::$bin        = dirname(__DIR__, 2) . '/bin/mcp-phpunit-warm';
        self::$fixtureDir = realpath(dirname(__DIR__) . '/Fixtures/project') ?: '';

        if (!is_file(self::$bin)) {
            self::markTestSkipped('bin/mcp-phpunit-warm missing');
        }
        if (!is_dir(self::$fixtureDir)) {
            self::markTestSkipped('fixture project missing');
        }
    }

    public function testInitializeAndListTools(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ];

        $responses = $this->invoke($messages, withProject: false);

        // initialize response
        self::assertSame(1, $responses[0]['id']);
        self::assertArrayHasKey('result', $responses[0]);
        self::assertSame('mcp-phpunit-warm', $responses[0]['result']['serverInfo']['name']);
        self::assertSame('0.4.0', $responses[0]['result']['serverInfo']['version']);

        // tools/list response
        self::assertSame(2, $responses[1]['id']);
        $tools = $responses[1]['result']['tools'];
        $names = array_column($tools, 'name');
        self::assertContains('phpunit_run', $names);
    }

    public function testPhpunitRunCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpunit_run',
                'arguments' => [],
            ]],
        ];

        $responses = $this->invoke($messages, withProject: true);

        $call = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 2))[0] ?? null;
        self::assertNotNull($call, 'no response for id=2');
        self::assertArrayHasKey('result', $call, 'expected result, got: ' . json_encode($call));

        $structured = $call['result']['structuredContent'] ?? null;
        self::assertIsArray($structured);
        self::assertArrayHasKey('exit_code', $structured);
        self::assertArrayHasKey('warm_boot', $structured);
        self::assertSame(0, $structured['exit_code'], 'fixture tests should pass');
        self::assertFalse($structured['warm_boot'], 'first call should be cold boot');

        // output is now JSON, not XML
        $output = $structured['output'] ?? '';
        self::assertIsString($output);
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, 'output must be valid JSON, got: ' . $output);
        self::assertArrayHasKey('tests', $decoded);
        self::assertArrayHasKey('assertions', $decoded);
        self::assertArrayHasKey('failures', $decoded);
        self::assertArrayHasKey('errors', $decoded);
        self::assertArrayHasKey('skipped', $decoded);
        self::assertArrayHasKey('time', $decoded);
        self::assertGreaterThan(0, $decoded['tests'], 'at least one test should have run');
        self::assertSame([], $decoded['failures'], 'fixture test should have no failures');
        self::assertSame([], $decoded['errors'], 'fixture test should have no errors');
    }

    public function testWarmBootOnSecondCall(): void
    {
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpunit_run',
                'arguments' => [],
            ]],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpunit_run',
                'arguments' => [],
            ]],
        ];

        $responses = $this->invoke($messages, withProject: true);

        $third = array_values(array_filter($responses, fn($r) => ($r['id'] ?? null) === 3))[0] ?? null;
        self::assertNotNull($third, 'no response for id=3');
        $structured = $third['result']['structuredContent'];
        self::assertTrue($structured['warm_boot'], 'second tools/call should reuse warm autoloader');
        self::assertSame(0, $structured['exit_code'], 'fixture tests should still pass on second run');

        // Second call output is also valid JSON
        $decoded = json_decode($structured['output'] ?? '', true);
        self::assertIsArray($decoded);
        self::assertGreaterThan(0, $decoded['tests']);
    }

    public function testNoJunitFileCreated(): void
    {
        // Capture any junit-*.xml files before the call
        $before = glob(sys_get_temp_dir() . '/phpunit_mcp_*') ?: [];

        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name'      => 'phpunit_run',
                'arguments' => [],
            ]],
        ];

        $this->invoke($messages, withProject: true);

        $after = glob(sys_get_temp_dir() . '/phpunit_mcp_*') ?: [];
        $new   = array_diff($after, $before);
        self::assertSame([], array_values($new), 'no phpunit_mcp_* temp files should be created');
    }

    /**
     * Regression for claude-supertool#265: a source edit made BETWEEN two calls on
     * the same long-lived daemon must be reflected on the second run.
     *
     * Before fork-per-call the warm process kept the first-loaded class in memory,
     * so the edit was invisible and the stale result was reported. With the fork
     * each call autoloads the on-disk class fresh, so the second run sees the edit.
     */
    public function testEditedSourceIsReloadedAcrossCalls(): void
    {
        $project = $this->makeRegressionProject(expected: 1, actual: 1);
        $source  = $project . '/src/Value.php';

        $proc = $this->spawnServer($project);

        try {
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities'    => new \stdClass(),
                'clientInfo'      => ['name' => 'phpunit', 'version' => '1.0.0'],
            ]]);
            $this->send($proc['stdin'], ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

            // First run: source returns the expected value → test passes.
            $this->send($proc['stdin'], $this->runCall(2));
            $first = $this->readResponse($proc['stdout'], 2);
            self::assertSame(0, $first['result']['structuredContent']['exit_code'], 'baseline run should pass' . $this->stderrTail($proc['stderr']));

            // Edit the source on disk so it now returns the WRONG value. touch() bumps
            // mtime past the 1s granularity so any CLI opcache revalidates the file.
            file_put_contents($source, $this->valueClass(2));
            touch($source, time() + 5);

            // Second run on the SAME daemon: the forked child must load the edited
            // class → assertion now fails → non-zero exit. A stale warm process would
            // still report exit 0.
            $this->send($proc['stdin'], $this->runCall(3));
            $second = $this->readResponse($proc['stdout'], 3);
            self::assertNotSame(
                0,
                $second['result']['structuredContent']['exit_code'],
                'edited source must be reloaded on the second run (stale class would still report exit 0)' . $this->stderrTail($proc['stderr'])
            );
        } finally {
            fclose($proc['stdin']);
            stream_get_contents($proc['stdout']);
            fclose($proc['stdout']);
            proc_close($proc['handle']);
        }
    }

    /**
     * @return array{handle: resource, stdin: resource, stdout: resource, stderr: string}
     */
    private function spawnServer(string $project): array
    {
        // Capture stderr to a file (not /dev/null) so a CI failure has diagnostics.
        $stderr = $project . '/server.stderr';
        $cmd = [
            self::$bin,
            '--no-prewarm',
            '--working-dir=' . $project,
            '--config=' . $project . '/phpunit.xml',
        ];

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
        );
        self::assertIsResource($proc);

        return ['handle' => $proc, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $stderr];
    }

    private function stderrTail(string $path): string
    {
        $contents = @file_get_contents($path);

        return ($contents === false || $contents === '') ? '' : ' | server stderr: ' . substr($contents, -1500);
    }

    /**
     * @param resource             $stdin
     * @param array<string,mixed>  $message
     */
    private function send($stdin, array $message): void
    {
        fwrite($stdin, json_encode($message) . "\n");
        fflush($stdin);
    }

    /**
     * Block reading newline-delimited JSON-RPC until the response with $id arrives.
     *
     * @param resource $stdout
     * @return array<string,mixed>
     */
    private function readResponse($stdout, int $id): array
    {
        while (($line = fgets($stdout)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        self::fail("no response for id={$id}");
    }

    /**
     * @return array<string,mixed>
     */
    private function runCall(int $id): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
            'name'      => 'phpunit_run',
            'arguments' => [],
        ]];
    }

    private function makeRegressionProject(int $expected, int $actual): string
    {
        $dir = sys_get_temp_dir() . '/phpunit_mcp_regr_' . bin2hex(random_bytes(6));
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/tests', 0777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/src/Value.php', $this->valueClass($actual));
        file_put_contents($dir . '/tests/ValueRegressionTest.php', <<<PHP
            <?php

            declare(strict_types=1);

            require_once __DIR__ . '/../src/Value.php';

            use PHPUnit\\Framework\\TestCase;

            final class ValueRegressionTest extends TestCase
            {
                public function testValue(): void
                {
                    self::assertSame({$expected}, \\McpRegressionValue::get());
                }
            }
            PHP);
        file_put_contents($dir . '/phpunit.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit colors="false" failOnRisky="false" failOnWarning="false">
                <testsuites>
                    <testsuite name="regression">
                        <directory>tests</directory>
                    </testsuite>
                </testsuites>
            </phpunit>
            XML);

        return $dir;
    }

    private function valueClass(int $value): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nfinal class McpRegressionValue\n{\n    public static function get(): int\n    {\n        return {$value};\n    }\n}\n";
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @return list<array<string,mixed>>
     */
    private function invoke(array $messages, bool $withProject): array
    {
        $args = ['--no-prewarm'];
        if ($withProject) {
            $args[] = '--working-dir=' . self::$fixtureDir;
            $args[] = '--config=' . self::$fixtureDir . '/phpunit.xml';
        }

        $cmd = array_merge([self::$bin], $args);

        $stdin = '';
        foreach ($messages as $m) {
            $stdin .= json_encode($m) . "\n";
        }

        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $responses = [];
        foreach (explode("\n", $stdout) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $responses[] = $decoded;
            }
        }
        self::assertNotEmpty($responses, 'no responses parsed. stdout=' . $stdout . ' stderr=' . $stderr);
        return $responses;
    }
}
