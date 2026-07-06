# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities.

Report privately using GitHub's
[private vulnerability reporting](https://github.com/GhazanfarSheikh/POS/security/advisories/new),
or contact the maintainer directly.

You will receive an acknowledgement within 72 hours and a remediation timeline
after triage.

## Supported versions

Only the latest `main` is actively maintained and patched.

## Handling secrets

- Never commit credentials, API keys, tokens, or `.env` files.
- Use environment variables and a secrets manager in every environment.
- If a secret is exposed, rotate it immediately and purge it from history.
