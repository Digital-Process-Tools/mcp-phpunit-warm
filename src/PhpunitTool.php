<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use Mcp\Capability\Attribute\McpTool;

final class PhpunitTool
{
    private PhpunitRunner $runner;

    public function __construct(?PhpunitRunner $runner = null)
    {
        $this->runner = $runner ?? PhpunitRunner::instance();
    }

    /**
     * Run PHPUnit tests. Config and working dir are pinned at server startup
     * (--working-dir and --config flags passed to mcp-phpunit-warm).
     *
     * @param string|null $testFile Absolute path to a test file or directory (optional — runs full suite if omitted)
     * @param string|null $filter   --filter pattern to run specific tests within the file
     * @param string|null $group    --group name to restrict execution to a named group
     * @return array{exit_code: int, output: string, warm_boot: bool, error?: string, error_class?: string, trace?: string}
     */
    #[McpTool(name: 'phpunit_run', description: 'Run PHPUnit tests. Server-pinned config. Returns JUnit XML output + exit code.')]
    public function run(
        ?string $testFile = null,
        ?string $filter = null,
        ?string $group = null,
    ): array {
        $config = getenv('MCP_PHPUNIT_CONFIG') ?: null;

        // Containment: phpunit autoloads + executes the supplied file in-process.
        // A hostile MCP client must NOT be able to point this at arbitrary PHP
        // files on the host (full RCE in the daemon's identity). Constrain to
        // realpath(cwd) — set at boot via --working-dir.
        if ($testFile !== null) {
            $cwd = realpath(getcwd() ?: '.');
            $real = realpath($testFile);
            if ($cwd === false || $real === false || ($real !== $cwd && !str_starts_with($real, $cwd . DIRECTORY_SEPARATOR))) {
                return [
                    'exit_code'   => -1,
                    'output'      => '',
                    'warm_boot'   => $this->runner->isWarm(),
                    'error'       => 'phpunit_run: testFile is outside the configured working directory.',
                    'error_class' => 'SecurityError',
                    'trace'       => '',
                ];
            }
        }

        $argv = ['phpunit'];

        if ($config !== null) {
            $argv[] = '--configuration';
            $argv[] = $config;
        }

        if ($filter !== null) {
            $argv[] = '--filter';
            $argv[] = $filter;
        }

        if ($group !== null) {
            $argv[] = '--group';
            $argv[] = $group;
        }

        if ($testFile !== null) {
            $argv[] = $testFile;
        }

        try {
            return $this->runner->run($argv);
        } catch (\Throwable $e) {
            return [
                'exit_code'   => -1,
                'output'      => '',
                'warm_boot'   => $this->runner->isWarm(),
                'error'       => $e->getMessage(),
                'error_class' => $e::class,
                'trace'       => $e->getTraceAsString(),
            ];
        }
    }
}
