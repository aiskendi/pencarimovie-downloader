# Standalone Binary Packaging (FrankenPHP App Embedding)

FrankenPHP supports embedding the complete PHP application source code, Composer vendor libraries, and static assets directly into a self-contained static executable.

This guide explains how PencariMovie Downloader is packaged into a single standalone binary.

---

## 1. Storage & Persistence Design

When running as an embedded binary, the source code and assets are extracted into an ephemeral directory (e.g. `/tmp/frankenphp_<hash>`).

To ensure MadelineProto sessions, cached credentials, and configurations persist across app restarts and reboots:

- [`backend.php`](backend.php) implements [`fd_get_storage_dir()`](backend.php:16) and [`fd_storage_path()`](backend.php:73).
- Writable storage is dynamically discovered in order of precedence:
  1. `PENCARIMOVIE_STORAGE_DIR` environment variable.
  2. Current working directory (`./storage`).
  3. Non-ephemeral app directory (`__DIR__/storage`).
  4. User home directory (`~/.pencarimovie-downloader/storage` or `%USERPROFILE%\.pencarimovie-downloader\storage`).

---

## 2. Docker Multi-Stage Build

A Docker-based build definition is provided in [`static-build.Dockerfile`](static-build.Dockerfile).

### Build Stages:

1. **Composer Optimizer** (`composer:2`):
   - Installs production dependencies without dev tools (`composer install --no-dev --optimize-autoloader`).
2. **FrankenPHP Static Builder** (`dunglas/frankenphp:static-builder-gnu`):
   - Configures required PHP extensions (`mbstring`, `openssl`, `curl`, `sodium`, `zip`, `fileinfo`, `pcntl`, `posix`, etc.).
   - Embeds app code, Caddyfile, vendor directory, and frontend assets into the binary via `EMBED=dist/app/ ./build-static.sh`.

---

## 3. How to Build the Standalone Binary

### Automated Script (Linux / macOS / WSL)

Run the script [`scripts/build-static-binary.sh`](scripts/build-static-binary.sh):

```bash
./scripts/build-static-binary.sh
```

The output binary will be placed at `dist/pencarimovie-static-linux-x86_64`.

### Manual Build Steps

```bash
# 1. Build the Docker image
docker build -t pencarimovie-static -f static-build.Dockerfile .

# 2. Extract the compiled executable
docker create --name pencarimovie-static-tmp pencarimovie-static
docker cp pencarimovie-static-tmp:/go/src/app/dist/frankenphp-linux-x86_64 ./pencarimovie-static
docker rm pencarimovie-static-tmp
chmod +x ./pencarimovie-static
```

---

## 4. Running the Standalone Binary

### Start the Web Server

```bash
./pencarimovie-static php-server
```

### Custom Port / Host

```bash
./pencarimovie-static php-server --listen 0.0.0.0:8088
```

### With Automatic HTTPS / Domain

```bash
./pencarimovie-static php-server --domain my-domain.com
```

### Run CLI Commands

```bash
./pencarimovie-static php-cli -r "echo 'PencariMovie Downloader running on embedded PHP ' . PHP_VERSION;"
```
