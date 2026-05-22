# Contributing

Thanks for the interest. This project is small and intentionally focused.

## Reporting issues

Open a GitHub issue with:

- PHPUnit version (`composer show phpunit/phpunit`)
- PHP version (`php -v`)
- MCP client (Claude Desktop, Cline, ...)
- Repro: minimal `phpunit.xml` + the failing command

## Pull requests

1. Fork, branch from `main`.
2. Add a test for the change (`tests/Unit` for logic, `tests/Integration` for end-to-end stdio behavior).
3. Run the suite:
   ```bash
   ./vendor/bin/phpunit --no-coverage
   ```
4. Open the PR with a one-paragraph summary of the change.

## What we'll merge

- Bug fixes with a regression test.
- PHPUnit version compatibility shims (static singleton resets for new versions).
- New MCP tools (e.g. `phpunit_list_suites`) that have a clear use case from an MCP client.
- Doc / README improvements.

## What we won't merge

- Features that shell out to `vendor/bin/phpunit` — defeats the in-process warm purpose.
- Changes that re-introduce stdout leaking into the MCP stdio transport.

## Local development

```bash
git clone https://github.com/Digital-Process-Tools/mcp-phpunit-warm.git
cd mcp-phpunit-warm
composer install
./vendor/bin/phpunit --no-coverage
```

Smoke test the binary against the fixture project:

```bash
bin/mcp-phpunit-warm --working-dir=tests/Fixtures/project --config=tests/Fixtures/project/phpunit.xml
# (then paste MCP JSON-RPC on stdin)
```
