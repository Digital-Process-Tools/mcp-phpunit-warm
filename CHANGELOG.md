# Changelog

All notable changes to this project will be documented in this file.

This project follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
