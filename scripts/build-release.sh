#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DIST_DIR="$ROOT_DIR/dist"
TMP_DIR="$DIST_DIR/.build-tmp"

copy_public_root_windows() {
  local dest="$1"

  cp "$ROOT_DIR/backend.php" "$dest/"
  cp "$ROOT_DIR/index.php" "$dest/"
  cp "$ROOT_DIR/router.php" "$dest/"
  cp "$ROOT_DIR/composer.json" "$dest/"
  cp "$ROOT_DIR/composer.lock" "$dest/"
  cp "$ROOT_DIR/composer.phar" "$dest/"
  cp "$ROOT_DIR/install.bat" "$dest/"
  cp "$ROOT_DIR/start.bat" "$dest/"
  cp "$ROOT_DIR/restart.bat" "$dest/"
  cp "$ROOT_DIR/stop.bat" "$dest/"
  cp "$ROOT_DIR/package.json" "$dest/"
  cp "$ROOT_DIR/README.md" "$dest/"
  cp "$ROOT_DIR/LICENSE" "$dest/"
  cp "$ROOT_DIR/THIRD_PARTY_NOTICES.md" "$dest/"
  cp "$ROOT_DIR/SECURITY.md" "$dest/"
  cp -R "$ROOT_DIR/public" "$dest/public"
  mkdir -p "$dest/storage"
  cp "$ROOT_DIR/storage/.gitkeep" "$dest/storage/.gitkeep" 2>/dev/null || true
  cp "$ROOT_DIR/storage/config.example.json" "$dest/storage/config.example.json"
}

copy_public_root_unix() {
  local dest="$1"

  cp "$ROOT_DIR/backend.php" "$dest/"
  cp "$ROOT_DIR/index.php" "$dest/"
  cp "$ROOT_DIR/router.php" "$dest/"
  cp "$ROOT_DIR/composer.json" "$dest/"
  cp "$ROOT_DIR/composer.lock" "$dest/"
  cp "$ROOT_DIR/composer.phar" "$dest/"
  cp "$ROOT_DIR/install.sh" "$dest/"
  cp "$ROOT_DIR/install-termux.sh" "$dest/"
  cp "$ROOT_DIR/start.sh" "$dest/"
  cp "$ROOT_DIR/start-termux.sh" "$dest/"
  cp "$ROOT_DIR/restart.sh" "$dest/"
  cp "$ROOT_DIR/restart-termux.sh" "$dest/"
  cp "$ROOT_DIR/stop.sh" "$dest/"
  cp "$ROOT_DIR/package.json" "$dest/"
  cp "$ROOT_DIR/README.md" "$dest/"
  cp "$ROOT_DIR/LICENSE" "$dest/"
  cp "$ROOT_DIR/THIRD_PARTY_NOTICES.md" "$dest/"
  cp "$ROOT_DIR/SECURITY.md" "$dest/"
  cp -R "$ROOT_DIR/public" "$dest/public"
  mkdir -p "$dest/storage"
  cp "$ROOT_DIR/storage/.gitkeep" "$dest/storage/.gitkeep" 2>/dev/null || true
  cp "$ROOT_DIR/storage/config.example.json" "$dest/storage/config.example.json"
}

cleanup() {
  rm -rf "$TMP_DIR"
}

trap cleanup EXIT

mkdir -p "$DIST_DIR"
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"

build_unix_package() {
  local target="$1"
  local source_file="$2"
  local package_target="${target#frankenphp-}"
  local build_dir="$TMP_DIR/pencarimovie-downloader-$package_target"

  if [ ! -f "$source_file" ]; then
    echo "Missing FrankenPHP source: $source_file"
    exit 1
  fi

  mkdir -p "$build_dir/bin"
  copy_public_root_unix "$build_dir"

  # Unix-optimised php.ini (renamed from .unix to standard .ini)
  cp "$ROOT_DIR/bin/php.ini.unix" "$build_dir/bin/php.ini"
  cp "$ROOT_DIR/bin/php" "$build_dir/bin/php"
  cp "$source_file" "$build_dir/bin/frankenphp"
  chmod +x "$build_dir/bin/frankenphp" "$build_dir/bin/php"
  chmod +x "$build_dir"/*.sh

  if [ -f "$ROOT_DIR/vendor/autoload.php" ]; then
    cp -R "$ROOT_DIR/vendor" "$build_dir/vendor"
  fi

  tar -C "$TMP_DIR" -czf "$DIST_DIR/pencarimovie-downloader-$package_target.tar.gz" "pencarimovie-downloader-$package_target"
}

build_windows_package() {
  local source_zip="$ROOT_DIR/frankenphp-windows-x86_64.zip"
  local build_dir="$TMP_DIR/pencarimovie-downloader-windows-x86_64"
  local extracted="$TMP_DIR/windows-src"

  if [ ! -f "$source_zip" ]; then
    echo "Missing Windows FrankenPHP archive: $source_zip"
    exit 1
  fi

  mkdir -p "$build_dir/bin" "$extracted"
  unzip -q "$source_zip" -d "$extracted"

  copy_public_root_windows "$build_dir"

  # Extract bin/ from the official FrankenPHP Windows release ZIP only
  # Files are extracted to root (not a bin/ subdir), so copy everything
  cp -R "$extracted/"* "$build_dir/bin/"

  if [ -f "$ROOT_DIR/vendor/autoload.php" ]; then
    cp -R "$ROOT_DIR/vendor" "$build_dir/vendor"
  fi

  (cd "$TMP_DIR" && zip -qr "$DIST_DIR/pencarimovie-downloader-windows-x86_64.zip" "pencarimovie-downloader-windows-x86_64")
}

build_windows_package
build_unix_package "frankenphp-linux-x86_64" "$ROOT_DIR/frankenphp-linux-x86_64"
build_unix_package "frankenphp-linux-aarch64" "$ROOT_DIR/frankenphp-linux-aarch64"
build_unix_package "frankenphp-linux-aarch64-gnu" "$ROOT_DIR/frankenphp-linux-aarch64-gnu"
build_unix_package "frankenphp-mac-arm64" "$ROOT_DIR/frankenphp-mac-arm64"
build_unix_package "frankenphp-mac-x86_64" "$ROOT_DIR/frankenphp-mac-x86_64"

echo "Release archives created in $DIST_DIR"
