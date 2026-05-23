# Security Policy

## Supported versions

Only the latest published release is supported for security fixes unless a release note says otherwise.

## Reporting a vulnerability

Do not post bot tokens, API hashes, session files, or private URLs in public issues.

If you find a vulnerability, report it privately to the project maintainer through the contact method listed on the GitHub repository. Include:

- affected version or release package name
- operating system
- steps to reproduce
- impact summary
- whether a token, session, or LAN endpoint was exposed

## Local network exposure

PencariMovie Downloader is designed as a local/LAN downloader. The default launcher binds to `0.0.0.0:8088` so devices on the same trusted network can open it.

Do not expose port `8088` directly to the public internet. Anyone who can reach the app may interact with the local downloader endpoints.

## Secrets

Treat these files and values as private:

- Telegram bot token
- Telegram API ID/hash if customized
- `storage/config.json`
- `storage/.secret.key`
- MadelineProto session files under `storage/`
- logs that may contain file IDs or request metadata

Never include real secrets in bug reports, screenshots, release ZIPs, or source commits.
