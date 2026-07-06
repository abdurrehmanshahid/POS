# Contributing

Thanks for working on POS. This is a protected, trunk-based repository — please
follow the workflow below so changes stay safe and reviewable.

## Golden rules

- `main` is protected. You **cannot** push to it directly.
- Every change lands via a pull request that passes CI and review.
- Keep PRs small and focused. One logical change per PR.

## Workflow

1. **Branch** off the latest `main`:

   ```bash
   git checkout main && git pull
   git checkout -b feature/short-description
   ```

2. **Commit** using [Conventional Commits](https://www.conventionalcommits.org/):

   ```text
   feat(payments): add cash rounding rules
   fix(inventory): prevent negative stock on refund
   ```

3. **Push** and open a pull request. Fill in the PR template.

4. **Pass the gates:**
   - CI is green
   - At least one approving review
   - All review conversations resolved
   - Branch is up to date with `main` (linear history is enforced)

5. **Merge** with *Squash and merge*. Delete the branch afterwards.

## Local quality checks

Run the same checks CI runs before you push (adjust to the stack):

```bash
npm run lint && npm test   # or the Python / other equivalent
```

## Security

Never commit secrets, `.env` files, keys, or customer data. Report
vulnerabilities privately per [SECURITY.md](SECURITY.md).
