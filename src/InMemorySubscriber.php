<?php

declare(strict_types=1);

namespace Dpt\McpPhpunitWarm;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * In-memory collector for PHPUnit test events.
 *
 * PHPUnit 10/11/12 subscriber interfaces each declare a single notify() with a
 * specific event type — you cannot merge them into one class. Instead, this class
 * exposes five inner subscriber objects (one per interface) that all write back to
 * the same collector. Register all five on the EventFacade before Application::run().
 *
 * After the run, call result() to get a JSON-serializable summary equivalent to the
 * JUnit XML output — without the temp-file round-trip.
 */
final class InMemorySubscriber
{
    private int $tests = 0;
    private int $assertions = 0;

    /** @var list<array{class: string, method: string, file: string, line: int, message: string}> */
    private array $failures = [];

    /** @var list<array{class: string, method: string, file: string, line: int, message: string}> */
    private array $errors = [];

    /** @var list<array{class: string, method: string, file: string, line: int, message: string}> */
    private array $skipped = [];

    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function reset(): void
    {
        $this->tests      = 0;
        $this->assertions = 0;
        $this->failures   = [];
        $this->errors     = [];
        $this->skipped    = [];
        $this->startTime  = microtime(true);
    }

    /**
     * Returns the five subscriber objects to register on the EventFacade.
     *
     * @return list<\PHPUnit\Event\Subscriber>
     */
    public function subscribers(): array
    {
        return [
            new class($this) implements PassedSubscriber {
                public function __construct(private readonly InMemorySubscriber $collector) {}
                public function notify(Passed $event): void { /* counts tracked in Finished */ }
            },
            new class($this) implements FinishedSubscriber {
                public function __construct(private readonly InMemorySubscriber $collector) {}
                public function notify(Finished $event): void {
                    $this->collector->recordFinished($event);
                }
            },
            new class($this) implements FailedSubscriber {
                public function __construct(private readonly InMemorySubscriber $collector) {}
                public function notify(Failed $event): void {
                    $this->collector->recordFailed($event);
                }
            },
            new class($this) implements ErroredSubscriber {
                public function __construct(private readonly InMemorySubscriber $collector) {}
                public function notify(Errored $event): void {
                    $this->collector->recordErrored($event);
                }
            },
            new class($this) implements SkippedSubscriber {
                public function __construct(private readonly InMemorySubscriber $collector) {}
                public function notify(Skipped $event): void {
                    $this->collector->recordSkipped($event);
                }
            },
        ];
    }

    /**
     * @internal Called by inner subscriber objects only.
     */
    public function recordFinished(Finished $event): void
    {
        $this->tests++;
        $this->assertions += $event->numberOfAssertionsPerformed();
    }

    /**
     * @internal Called by inner subscriber objects only.
     */
    public function recordFailed(Failed $event): void
    {
        $this->failures[] = $this->testEntry($event->test(), $event->throwable()->message());
    }

    /**
     * @internal Called by inner subscriber objects only.
     */
    public function recordErrored(Errored $event): void
    {
        $this->errors[] = $this->testEntry($event->test(), $event->throwable()->message());
    }

    /**
     * @internal Called by inner subscriber objects only.
     */
    public function recordSkipped(Skipped $event): void
    {
        $this->skipped[] = $this->testEntry($event->test(), $event->message());
    }

    /**
     * Returns a JSON-serializable summary of the completed run.
     *
     * Shape: {tests, assertions, failures, errors, skipped, time}
     * Each failure/error/skipped entry: {class, method, file, line, message}
     *
     * @return array{tests: int, assertions: int, failures: list<array{class: string, method: string, file: string, line: int, message: string}>, errors: list<array{class: string, method: string, file: string, line: int, message: string}>, skipped: list<array{class: string, method: string, file: string, line: int, message: string}>, time: float}
     */
    public function result(): array
    {
        return [
            'tests'      => $this->tests,
            'assertions' => $this->assertions,
            'failures'   => $this->failures,
            'errors'     => $this->errors,
            'skipped'    => $this->skipped,
            'time'       => round(microtime(true) - $this->startTime, 4),
        ];
    }

    /**
     * @return array{class: string, method: string, file: string, line: int, message: string}
     */
    private function testEntry(\PHPUnit\Event\Code\Test $test, string $message): array
    {
        return [
            'class'   => $test instanceof TestMethod ? $test->className() : '',
            'method'  => $test instanceof TestMethod ? $test->methodName() : $test->id(),
            'file'    => $test->file(),
            'line'    => $test instanceof TestMethod ? $test->line() : 0,
            'message' => $message,
        ];
    }
}
