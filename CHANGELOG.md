# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] — 2026-05-22

### Added

- `InMemorySubscriber`: collects PHPUnit test events in-memory via the PHPUnit 10/11/12 event system. No temp file written, no XML serialised, no disk I/O on the hot path.
- `PhpunitRunner::prewarm()`: runs `--list-tests` once at daemon startup to trigger bootstrap + autoload before the first real test call arrives (~800 ms saved on first warm call).
- `--no-prewarm` flag on `bin/mcp-phpunit-warm` (prewarm is on by default).
- `PhpunitRunner::instance()` / `setShared()`: shared singleton so the pre-warmed runner is reused by `PhpunitTool` without a DI container.

### Changed

- `output` field in the MCP response is now a JSON string (`{tests, assertions, failures, errors, skipped, time}`) instead of JUnit XML. Each failure/error/skipped entry carries `{class, method, file, line, message}`.
- `--log-junit` removed from PHPUnit argv — no temp file created.
- Supertool validator adapter (`validators/phpunit-mcp/phpunit-mcp.py`) updated to parse the new JSON shape instead of JUnit XML. SCHEMA output is unchanged.
- `bin/mcp-phpunit-warm` version bumped to `0.2.0`.

## [0.1.0] — 2026-05-22

### Added

- Warm-process MCP server `mcp-phpunit-warm` keeping PHPUnit's autoloader and bootstrap hot across calls.
- `phpunit_run` tool exposing test execution via MCP stdio, with optional `testFile`, `filter`, and `group` arguments.
- In-process static singleton reset between calls (EventFacade, Registry, OutputFacade, CodeCoverage, ErrorHandler) so PHPUnit's sealed event system can be re-initialized without restarting the process.
- `--no-output` flag forces PHPUnit's NullPrinter, preventing DefaultPrinter from writing to `php://stdout` and corrupting the MCP stdio transport.
- JUnit XML output captured via `--log-junit` temp file, returned as structured `output` field.
- `warm_boot: true` on second and subsequent calls — confirms autoloader reuse.
- Standalone CLI: `--working-dir`, `--config` flags pinned at server start.
- PHPUnit unit + integration tests covering boot, tool listing, and warm reuse.
