# POS

Point of Sale system.

## Status

![CI](https://github.com/GhazanfarSheikh/POS/actions/workflows/ci.yml/badge.svg)

## Overview

Enterprise-grade Point of Sale platform. This repository follows a protected
trunk-based workflow: `main` is always releasable, and all changes land through
reviewed pull requests that pass CI.

## Getting started

```bash
git clone https://github.com/GhazanfarSheikh/POS.git
cd POS
# install dependencies for your stack, then run the app
```

## Development workflow

1. Create a branch off `main` using the naming convention below.
2. Open a pull request early; keep it focused and small.
3. Ensure CI is green and at least one approval is granted.
4. Resolve all review conversations, then squash-merge.

### Branch naming

| Prefix      | Purpose                        |
| ----------- | ------------------------------ |
| `feature/`  | New functionality              |
| `fix/`      | Bug fixes                      |
| `chore/`    | Tooling, deps, housekeeping    |
| `docs/`     | Documentation only             |
| `refactor/` | Internal changes, no behaviour |
| `hotfix/`   | Urgent production fixes        |

### Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat(cart): add split-tender payment support
fix(receipt): correct tax rounding on discounts
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

Copyright © 2026 Ghazanfar Sheikh. All rights reserved.

This is proprietary software. No part of this codebase may be copied, modified,
distributed, or used without prior written permission from the copyright holder.
