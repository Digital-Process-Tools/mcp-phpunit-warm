# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.4.1] — 2026-08-22

### Fixed

- **Child output no longer reaches the MCP transport (#3).** The forked test child inherits stdout, which for this server *is* the protocol stream. `runInProcess()` buffered PHPUnit's own output, but a test that fatals or calls `exit()` never reaches the matching `ob_get_clean()` — PHP flushes every active buffer during shutdown, straight to fd 1. Whatever user code had printed then landed in front of the next JSON-RPC frame and, being unterminated, glued itself to it (`</html>{"jsonrpc":...}`). Clients that frame on newlines could not parse that line and blocked until their own timeout on a result that had already arrived — measured at five minutes per call against a host application that renders an HTML error page on fatals. The child now seals stdout immediately after the fork with a never-ended output buffer whose callback returns nothing, so output from any phase, shutdown included, writes zero bytes. Results are unaffected: the child ships them over its socket pair.

- **A dying child now reports what it printed, instead of losing it (#3).** Sealing stdout alone would have traded a loud failure for a silent one — a debug `echo`, or the error page a framework rendered on its way down, would simply vanish. The seal installs a shutdown hook that ships the buffered output over the result socket instead, so the crash payload carries it under the existing `echo` key, alongside a message naming the fatal (`... died before shipping a result: <message> in <file>:<line>`) when PHP recorded one. The bytes that used to corrupt the channel are now the diagnostic that explains the crash. The hook fires only on the crash path: a completed run writes its payload and dies by `SIGKILL`, which runs no shutdown function.

### Added

- Integration test `ServerStdioTest::testChildOutputNeverReachesTheProtocolStream`: a fixture project whose test echoes and then exits, asserting that the transport carries only parseable JSON-RPC frames *and* that the crash result reports the child's output and cause of death.
- Transport purity is now asserted in `tearDown` for every integration test, not only the one written for it — a leak is a property of the transport, so it should fail wherever it appears rather than only where someone thought to look.

## [0.3.0] — 2026-05-23

### Security

- **Path containment on `phpunit_run`.** PHPUnit autoloads + executes the supplied `$testFile` in-process. Previously any path was accepted, giving a hostile MCP client full RCE in the daemon's identity by pointing at arbitrary `*.php` files. `PhpunitTool::run()` now realpath-canonicalises `$testFile` against `realpath(getcwd())` (pinned at boot via `--working-dir`) and returns a `SecurityError` before PHPUnit boots. `$testFile = null` (full-suite run) is unaffected.

### Added

- Unit tests `PhpunitToolContainmentTest::testRejectsTestFileOutsideWorkingDir` + `testAcceptsNullTestFile`.

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
