# PencariMovie Downloader release packaging

This project uses one source tree and multiple OS-specific release packages.

Each package contains the same app code, but a different FrankenPHP runtime in `bin/`.

## Release artifacts

- `pencarimovie-downloader-windows-x86_64.zip`
- `pencarimovie-downloader-linux-x86_64.tar.gz`
- `pencarimovie-downloader-linux-x86_64-gnu.tar.gz`
- `pencarimovie-downloader-linux-x86_64-mimalloc.tar.gz`
- `pencarimovie-downloader-linux-aarch64.tar.gz`
- `pencarimovie-downloader-linux-aarch64-gnu.tar.gz`
- `pencarimovie-downloader-mac-arm64.tar.gz`
- `pencarimovie-downloader-mac-x86_64.tar.gz`
- `pencarimovie-downloader-android-arm64.apk` — Standalone Android APK (no Termux required)

## Composer policy

Release packages should include `vendor/`.

Release packages also include a bundled [`php.ini`](bin/php.ini) so the required extensions are enabled without manual setup.

If `vendor/autoload.php` exists, `install.bat` and `install.sh` skip Composer.

If `vendor/autoload.php` is missing, Composer or `composer.phar` is used as a source-install fallback.

## Windows package

The Windows package uses the bundled runtime in `bin/frankenphp.exe` plus the required Windows DLLs in `bin/`.

**Important:** The `bin/` directory is extracted from `frankenphp-windows-x86_64.zip` (official FrankenPHP Windows release), not from the repo's `bin/` directory. This ensures only Windows-appropriate files are included — no Linux binaries, no `.sh` scripts, no Termux/Android files.

Build on Windows:

```bat
scripts\package-windows.bat
```

Output:

```text
dist\pencarimovie-downloader-windows-x86_64.zip
```

### Windows package contents

- App files: `backend.php`, `index.php`, `router.php`, `composer.*`, `vendor/`
- Scripts: `install.bat`, `start.bat`, `stop.bat`, `restart.bat` (`.bat` only — no `.sh`)
- Runtime: `bin/frankenphp.exe`, `bin/php.exe`, all required Windows DLLs, Windows `php.ini`
- Frontend: `public/`
- Config: `storage/`

## Linux/macOS packages

Download the correct FrankenPHP binary first, then pass it to the Unix packaging script.

The new one-command builder reads the local FrankenPHP binaries from the `frankenphp-*` files in the repo root, copies them into `bin/`, copies `bin/php`, renames `bin/php.ini.unix` → `bin/php.ini`, and then creates the archives.

Examples:

```bash
./scripts/package-unix.sh frankenphp-linux-x86_64 /path/to/frankenphp-linux-x86_64
./scripts/package-unix.sh frankenphp-linux-aarch64 /path/to/frankenphp-linux-aarch64
./scripts/package-unix.sh frankenphp-linux-aarch64-gnu /path/to/frankenphp-linux-aarch64-gnu
./scripts/package-unix.sh frankenphp-mac-arm64 /path/to/frankenphp-mac-arm64
./scripts/package-unix.sh frankenphp-mac-x86_64 /path/to/frankenphp-mac-x86_64
```

Outputs:

```text
dist/pencarimovie-downloader-linux-x86_64.tar.gz
dist/pencarimovie-downloader-linux-aarch64.tar.gz
dist/pencarimovie-downloader-linux-aarch64-gnu.tar.gz
dist/pencarimovie-downloader-mac-arm64.tar.gz
dist/pencarimovie-downloader-mac-x86_64.tar.gz
```

### Unix package contents

- App files: `backend.php`, `index.php`, `router.php`, `composer.*`, `vendor/`
- Scripts: `install.sh`, `start.sh`, `stop.sh`, `restart.sh`, `install-termux.sh`, `start-termux.sh`, `restart-termux.sh` (`.sh` only — no `.bat`)
- Runtime: `bin/frankenphp`, `bin/php`, `bin/php.ini` (renamed from `php.ini.unix`)
- Frontend: `public/`
- Config: `storage/`

Target mapping:

- `frankenphp-linux-x86_64` -> `pencarimovie-downloader-linux-x86_64.tar.gz`
- `frankenphp-linux-x86_64-gnu` -> `pencarimovie-downloader-linux-x86_64-gnu.tar.gz`
- `frankenphp-linux-x86_64-mimalloc` -> `pencarimovie-downloader-linux-x86_64-mimalloc.tar.gz`
- `frankenphp-linux-aarch64` -> `pencarimovie-downloader-linux-aarch64.tar.gz`
- `frankenphp-linux-aarch64-gnu` -> `pencarimovie-downloader-linux-aarch64-gnu.tar.gz`
- `frankenphp-mac-arm64` -> `pencarimovie-downloader-mac-arm64.tar.gz`
- `frankenphp-mac-x86_64` -> `pencarimovie-downloader-mac-x86_64.tar.gz`

## OS-specific script separation

The packaging scripts ensure each OS package only includes the relevant scripts:

| Package type | Scripts included                                                                                   | Scripts excluded  |
| ------------ | -------------------------------------------------------------------------------------------------- | ----------------- |
| Windows      | `*.bat` — `install`, `start`, `stop`, `restart`                                                    | No `.sh` scripts  |
| Unix/Linux   | `*.sh` — `install`, `start`, `stop`, `restart`, `start-termux`, `install-termux`, `restart-termux` | No `.bat` scripts |

The `index.php` FrankenPHP entrypoint (which loads `backend.php`) is included in all packages.

## GitHub release flow

1. Run Composer once before packaging if `vendor/` is missing.
2. Run [`scripts/build-release.bat`](scripts/build-release.bat) on Windows or [`scripts/build-release.sh`](scripts/build-release.sh) on Unix/macOS.
3. The script produces every release archive automatically:
   - **Windows**: Extracts `bin/` from `frankenphp-windows-x86_64.zip`, copies `.bat` scripts only
   - **Unix**: Renames `php.ini.unix` → `php.ini`, copies `bin/php`, copies `.sh` scripts only
4. Upload all files from `dist/` to a GitHub Release.

End users should download only the package matching their OS and CPU.

## Android APK

The Android APK is a standalone app that bundles proot + the linux-aarch64 FrankenPHP binary + all project files as compressed assets. No Termux is required.

### Prerequisites

- Android SDK (set `ANDROID_HOME` or have `sdkmanager` in PATH)
- proot binary for Android ARM64 at `bin/proot-arm64-v8a` (or set `PROOT_ARM64` env var)
- proot binary for Android x86_64 at `bin/proot-x86_64` (or set `PROOT_X86_64` env var) — optional, for emulator support
- `vendor/` directory must exist (run `composer install` first)

### Build

```bash
bash scripts/package-android.sh
```

Output:

```
android/app/build/outputs/apk/release/app-release.apk
```

### Install on device

```bash
adb install android/app/build/outputs/apk/release/app-release.apk
```

### What the APK does

1. On first launch, extracts bundled `pencarimovie.tar.gz` and proot binary from APK assets to internal storage
2. User taps **Start Server** → foreground service runs:
   ```
   proot --link2symlink -0 -w <dir> -b <dir>:<dir> -b <tmp>:/tmp \
     /bin/sh -c 'export PATH=<dir>/bin:$PATH; exec frankenphp php-server --listen 0.0.0.0:8088 --root <dir>'
   ```
3. FrankenPHP serves the app on `http://0.0.0.0:8088`
4. User opens the browser to access the web UI
5. Tap **Stop Server** to shut down
