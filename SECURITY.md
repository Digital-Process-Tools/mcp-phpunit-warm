# Security

If you find a security issue, please email **security@digitalprocesstools.com** before opening a public issue.

The server runs PHPUnit inside a long-lived PHP process and processes test files under the configured `--working-dir`. Do not point it at directories containing untrusted code.
