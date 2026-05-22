<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm\Tests\Unit;

use Dpt\McpPhpunitWarm\PhpunitRunner;
use PHPUnit\Framework\TestCase;

final class PhpunitRunnerTest extends TestCase
{
    public function testIsWarmFalseBeforeBoot(): void
    {
        $runner = new PhpunitRunner();
        self::assertFalse($runner->isWarm());
    }
}
