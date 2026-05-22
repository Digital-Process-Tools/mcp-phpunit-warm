<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use Mcp\Capability\Attribute\McpTool;

final class PhpunitTool
{
    private PhpunitRunner $runner;

    public function __construct(?PhpunitRunner $runner = null)
    {
        $this->runner = $runner ?? new PhpunitRunner();
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
