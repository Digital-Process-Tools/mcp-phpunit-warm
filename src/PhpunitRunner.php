<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use PHPUnit\TextUI\Application;

/**
 * Runs PHPUnit in-process across multiple calls.
 *
 * PHPUnit 10/11/12 uses static singletons (EventFacade, Registry, OutputFacade, etc.)
 * that are sealed after the first run. We reset them via Reflection between calls.
 * The autoloader stays warm — class files are already parsed and opcode-cached.
 *
 * Output is captured via --log-junit to a temp file + --no-output to prevent PHPUnit's
 * DefaultPrinter from writing to php://stdout (which would corrupt the MCP stdio transport).
 */
final class PhpunitRunner
{
    private bool $warm = false;

    public function isWarm(): bool
    {
        return $this->warm;
    }

    /**
     * Run PHPUnit. Returns exit code, junit XML output, and warm_boot flag.
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

        $junitFile = tempnam(sys_get_temp_dir(), 'phpunit_mcp_');

        // --no-output: forces NullPrinter so DefaultPrinter never writes to php://stdout.
        // --no-coverage: skip coverage collection (expensive, not useful per MCP call).
        // --log-junit: structured results captured to temp file, read back as output.
        $fullArgv = array_merge($argv, [
            '--no-output',
            '--no-coverage',
            '--log-junit', $junitFile,
        ]);

        // ob_start captures any print/echo from Application internals (e.g. crash messages).
        ob_start();
        try {
            $app = new Application();
            $exitCode = $app->run($fullArgv);
        } finally {
            $echoed = ob_get_clean();
        }

        $junitXml = '';
        if (is_file($junitFile)) {
            $junitXml = (string) file_get_contents($junitFile);
            @unlink($junitFile);
        }

        // Combine junit XML with any internal echo output (crash messages etc.)
        $output = $junitXml;
        if (is_string($echoed) && $echoed !== '') {
            $output = $output !== '' ? $output . "\n" . $echoed : $echoed;
        }

        $this->warm = true;

        return [
            'exit_code' => $exitCode,
            'output'    => $output,
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
