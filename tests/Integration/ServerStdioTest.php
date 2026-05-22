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
        self::assertSame('0.2.0', $responses[0]['result']['serverInfo']['version']);

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
