# Contributing

Thanks for considering a contribution to CareWash Shift & Operations.

## Ways to contribute

You can help by:

- reporting bugs
- improving documentation
- proposing reusable features
- adding tests
- improving accessibility
- helping with internationalization
- simplifying deployment
- separating reusable functionality from business-specific configuration

## Before opening an issue

Please check existing issues first.

When reporting a bug, include:

- what you expected
- what happened
- steps to reproduce
- PHP version
- database type/version
- relevant error messages

Do **not** include passwords, database dumps, real employee/customer data, access tokens, or other private information.

## Pull requests

1. Fork the repository.
2. Create a focused branch.
3. Keep the change as small and understandable as practical.
4. Explain the reason for the change in the pull request.
5. Update documentation if behavior changes.
6. Do not commit production credentials or personal data.

Example:

```bash
git checkout -b feature/example
git add .
git commit -m "Add example feature"
git push origin feature/example
```

Then open a pull request against the default branch.

## Coding approach

The current codebase is an actively evolving PHP application. New contributions should, where practical:

- use parameterized database queries
- validate user input
- escape HTML output
- preserve audit/history records
- avoid hard-coded company-specific values
- keep configuration in `config.php` or another non-committed local configuration
- avoid adding secrets or personal data to fixtures

## Business-specific features

Some existing features were developed around laundry, care-service, pickup/delivery, and field operations.

When adding new functionality, prefer configurable or reusable designs where doing so does not make the code unnecessarily complex.

## License

By contributing, you agree that your contribution may be distributed under the repository's MIT License.
