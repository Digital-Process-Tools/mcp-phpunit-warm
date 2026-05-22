<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm\Tests\Unit;

use Dpt\McpPhpunitWarm\InMemorySubscriber;
use Dpt\McpPhpunitWarm\PhpunitRunner;
use PHPUnit\Framework\TestCase;

final class PhpunitRunnerTest extends TestCase
{
    public function testIsWarmFalseBeforeBoot(): void
    {
        $runner = new PhpunitRunner();
        self::assertFalse($runner->isWarm());
    }

    public function testInMemorySubscriberFreshResult(): void
    {
        $sub = new InMemorySubscriber();
        $result = $sub->result();

        self::assertSame(0, $result['tests']);
        self::assertSame(0, $result['assertions']);
        self::assertSame([], $result['failures']);
        self::assertSame([], $result['errors']);
        self::assertSame([], $result['skipped']);
        self::assertIsFloat($result['time']);
        self::assertGreaterThanOrEqual(0.0, $result['time']);
    }

    public function testInMemorySubscriberResetClearsState(): void
    {
        $sub = new InMemorySubscriber();

        // Simulate some elapsed time before reset
        usleep(1000);
        $sub->reset();

        $result = $sub->result();
        self::assertSame(0, $result['tests']);
        self::assertSame(0, $result['assertions']);
        self::assertSame([], $result['failures']);
        self::assertSame([], $result['errors']);
        self::assertSame([], $result['skipped']);
    }

    public function testInMemorySubscriberSubscribersReturnsFive(): void
    {
        $sub = new InMemorySubscriber();
        self::assertCount(5, $sub->subscribers());
    }

    public function testPhpunitRunnerSharedInstance(): void
    {
        // instance() returns same object on repeated calls
        $a = PhpunitRunner::instance();
        $b = PhpunitRunner::instance();
        self::assertSame($a, $b);
    }

    public function testPhpunitRunnerSetShared(): void
    {
        $runner = new PhpunitRunner();
        PhpunitRunner::setShared($runner);
        self::assertSame($runner, PhpunitRunner::instance());

        // Restore so other tests get a clean instance
        PhpunitRunner::setShared(new PhpunitRunner());
    }
}
