<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm\Resources;

use PHPUnit\Framework\TestCase;

/**
 * Throwaway test used only to warm the daemon's PHPUnit framework + the project's
 * phpunit.xml bootstrap at startup (see {@see \Dpt\McpPhpunitWarm\PhpunitRunner::prewarm()}).
 *
 * It must stay dependency-free: it pulls in zero user source/test classes, so the
 * long-lived parent process never loads a class that a later edit would render
 * stale. PHPUnit runs ONLY this file (passed as an explicit path argument, which
 * overrides the configured test suites) — the side effect we want is the framework
 * boot and the autoloader registration, not the assertion itself.
 */
final class PrewarmProbeTest extends TestCase
{
    public function testProbe(): void
    {
        self::assertTrue(true);
    }
}
