<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm\Tests\Unit;

use Dpt\McpPhpunitWarm\PhpunitRunner;
use Dpt\McpPhpunitWarm\PhpunitTool;
use PHPUnit\Framework\TestCase;

/**
 * Containment regression: PhpunitTool::run autoloads + executes the supplied
 * test file in-process. Without containment, a hostile MCP client could trigger
 * RCE by pointing $testFile at arbitrary PHP files on the host.
 */
final class PhpunitToolContainmentTest extends TestCase
{
    private string $cwdBackup;
    private string $workDir;
    private string $outsideDir;

    protected function setUp(): void
    {
        $this->cwdBackup  = getcwd() ?: '/';
        $this->workDir    = sys_get_temp_dir() . '/mcp-phpunit-work-' . bin2hex(random_bytes(4));
        $this->outsideDir = sys_get_temp_dir() . '/mcp-phpunit-outside-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0o700, true);
        mkdir($this->outsideDir, 0o700, true);
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        chdir($this->cwdBackup);
        @unlink($this->outsideDir . '/leak.php');
        @rmdir($this->workDir);
        @rmdir($this->outsideDir);
    }

    public function testRejectsTestFileOutsideWorkingDir(): void
    {
        // A payload file that would `echo PWNED` if phpunit autoloaded it.
        $leak = $this->outsideDir . '/leak.php';
        file_put_contents($leak, "<?php\necho 'PWNED';\n");

        $tool = new PhpunitTool(new PhpunitRunner());
        $result = $tool->run($leak);

        self::assertSame(-1, $result['exit_code']);
        self::assertSame('SecurityError', $result['error_class'] ?? null);
        self::assertStringContainsString('outside', $result['error'] ?? '');
        // Phpunit was NOT booted — daemon stays clean.
        self::assertFalse($result['warm_boot']);
    }

    public function testAcceptsNullTestFile(): void
    {
        // Containment must NOT block the null-testFile case (full-suite run).
        // We don't actually run phpunit here — just verify the guard lets null
        // through. The path check is inside `if ($testFile !== null)`.
        $tool = new PhpunitTool(new PhpunitRunner());
        // Reach into the guard by passing only filter/group — testFile stays null.
        // We expect either a real phpunit result OR an error UNRELATED to containment.
        $result = $tool->run(null, '__definitely_no_match__');
        self::assertNotSame('SecurityError', $result['error_class'] ?? null);
    }
}
