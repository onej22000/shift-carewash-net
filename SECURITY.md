# Security Policy

## Reporting a vulnerability

Please do not disclose a suspected security vulnerability in a public GitHub issue if the report contains exploit details, credentials, personal data, or other sensitive information.

Contact the maintainer privately through an appropriate GitHub-supported contact method first. If no private contact method is available, open a minimal issue asking for a private reporting channel without posting exploit details.

## Sensitive data

Never commit or post:

- database passwords
- production `config.php`
- API keys or access tokens
- session secrets
- employee or customer personal information
- production database exports
- real attendance/location records
- private facility or operational records

If a secret is committed accidentally, removing it in a later commit is not enough. Rotate/revoke the secret and remove it from Git history where appropriate.

## Production deployment

Operators should:

- use HTTPS
- keep PHP and dependencies patched
- restrict database privileges
- protect administrative endpoints
- review web-server access rules
- maintain backups
- audit accounts and access periodically
- use appropriate retention and privacy controls for workforce/location data

## Supported versions

The project is currently under active development and does not yet maintain a formal long-term-support release matrix. Security fixes should be applied to the latest version of the default branch unless a release notes otherwise.
