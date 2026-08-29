# Changelog

All notable changes to the PencariMovie Server / Downloader project will be documented in this file.

## [0.9.0] - 2026-08-29

### Added

- **Stremio & Nuvio Addon Integration**:
  - Implemented Stremio addon manifest and endpoints (`/manifest.json`, `/catalog/...`, `/meta/...`, `/stream/...`) in [`backend.php`](backend.php:2585).
  - Enables direct playback and library browsing from Stremio and Nuvio players with automatic Telegram bot stream resolution.
- **Windows System Tray Helper**:
  - Added background tray management scripts ([`tray.ps1`](tray.ps1:1), [`start-hidden.ps1`](start-hidden.ps1:1)) and tray icons ([`tray.ico`](tray.ico), [`tray.png`](tray.png)).
  - Runs FrankenPHP silently with hidden window and provides tray menu actions for opening the web dashboard and stopping the server.
- **One-File Installers with Start-Time OTA Updates**:
  - Added [`pencarimovie-windows.bat`](pencarimovie-windows.bat:1), [`pencarimovie-linux.sh`](pencarimovie-linux.sh:1), and [`pencarimovie-termux.sh`](pencarimovie-termux.sh:1).
  - Automatically queries GitHub `releases/latest` on start, updates the application files in-place while preserving `storage/` bot session data.
- **Version Check & Minimum Version Enforcement**:
  - Implemented version verification against WordPress API endpoint (`/fastdownloader/v1/version`) with local hourly caching.
  - Added full-screen update notification overlay in [`public/index.html`](public/index.html:27) and [`public/styles.css`](public/styles.css:681).
- **LAN IP Detection & Network Access**:
  - Cross-platform network IP discovery in [`start.bat`](start.bat:1), [`start.sh`](start.sh:1), and [`start-termux.sh`](start-termux.sh:1) displaying local and LAN network URLs upon startup.

### Changed

- **Frontend Streaming UI Overhaul**:
  - Integrated Netflix-style FlixBrowse UI directly into [`public/index.html`](public/index.html:1), [`public/app.js`](public/app.js:1), and [`public/styles.css`](public/styles.css:1).
  - Added rich search overlays, file detail views, inline Telegram stream player, and one-click MadelineProto downloader.
- **Backend Architecture & Security**:
  - Session-based authentication with automatic Telegram bot login via MadelineProto.
  - Eliminated plaintext configuration files; API credentials are securely fetched from the WordPress backend and encrypted with the bot token.
  - Enhanced FrankenPHP storage directory resolution to protect sessions in temp directory environments.
- **Launcher Scripts**:
  - Refactored [`start.bat`](start.bat:1), [`start.sh`](start.sh:1), [`start-termux.sh`](start-termux.sh:1), [`stop.bat`](stop.bat:1), [`stop.sh`](stop.sh:1), [`restart.bat`](restart.bat:1), [`restart.sh`](restart.sh:1), and [`restart-termux.sh`](restart-termux.sh:1) for unified port handling (default `8088`), clean output banners, and process lifecycle management.
- **Project Documentation**:
  - Updated [`README.md`](README.md:1) and [`RELEASE.md`](RELEASE.md:1) with Stremio setup guides, one-line installer commands, and packaging specifications.
