#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

echo "Installing Termux helpers for PencariMovie Downloader..."

if ! command -v proot >/dev/null 2>&1; then
  if command -v pkg >/dev/null 2>&1; then
    echo "Installing proot with pkg..."
    pkg install -y proot
  else
    echo "proot is required but pkg was not found. Install proot manually, then rerun this script."
    exit 1
  fi
fi

mkdir -p tmp
chmod 700 tmp 2>/dev/null || true

for FILE in \
  bin/frankenphp \
  bin/php \
  start.sh \
  stop.sh \
  restart.sh \
  install.sh \
  start-termux.sh \
  install-termux.sh \
  restart-termux.sh
do
  if [ -f "$FILE" ]; then
    chmod u+x "$FILE" 2>/dev/null || true
  fi
done

ARCH="$(uname -m 2>/dev/null || echo unknown)"
if [ "$ARCH" != "aarch64" ] && [ "$ARCH" != "arm64" ]; then
  echo "Warning: detected architecture is $ARCH. Most Android phones need the linux-aarch64 package."
fi

if [ ! -f vendor/autoload.php ]; then
  echo "Bundled vendor dependencies were not found; running install.sh..."
  bash install.sh
else
  echo "Bundled vendor dependencies found. Composer install is not needed."
fi

echo "Termux setup complete. Start with: bash start-termux.sh"
