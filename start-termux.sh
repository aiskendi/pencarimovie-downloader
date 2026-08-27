#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
FRANKENPHP_BIN="$ROOT_DIR/bin/frankenphp"
HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8088}"
TMP_DIR="$ROOT_DIR/tmp"
LOG_FILE="${LOG_FILE:-$ROOT_DIR/frankenphp.log}"
PID_FILE="$ROOT_DIR/.frankenphp.pid"

echo "Preparing Termux/proot runtime..."

if ! command -v proot >/dev/null 2>&1; then
  echo "proot is required on Termux for the bundled FrankenPHP runtime."
  echo "Install it with: pkg install proot"
  exit 1
fi

mkdir -p "$TMP_DIR"
chmod 700 "$TMP_DIR" 2>/dev/null || true

# Generate resolv.conf for proot DNS resolution on Android
cat <<'EOF' > "$TMP_DIR/resolv.conf"
nameserver 1.1.1.1
nameserver 8.8.8.8
nameserver 1.0.0.1
EOF
chmod 644 "$TMP_DIR/resolv.conf" 2>/dev/null || true

# Ensure execute permissions (Windows-originated archives lose +x bits)
for FILE in \
  "$FRANKENPHP_BIN" \
  "$ROOT_DIR/bin/php" \
  "$ROOT_DIR/bin/php.ini.unix" \
  "$ROOT_DIR/backend.php" \
  "$ROOT_DIR/index.php" \
  "$ROOT_DIR/router.php" \
  "$ROOT_DIR/start.sh" \
  "$ROOT_DIR/stop.sh" \
  "$ROOT_DIR/restart.sh" \
  "$ROOT_DIR/install.sh" \
  "$ROOT_DIR/install-termux.sh" \
  "$ROOT_DIR/start-termux.sh" \
  "$ROOT_DIR/restart-termux.sh"
do
  if [ -f "$FILE" ]; then
    chmod u+x "$FILE" 2>/dev/null || true
  fi
done

if [ ! -x "$FRANKENPHP_BIN" ]; then
  echo "FrankenPHP was not found or is not executable: $FRANKENPHP_BIN"
  echo "Use the linux-aarch64 release on Android/Termux, then run: bash install-termux.sh"
  exit 1
fi

# Install Composer dependencies if missing (same as pencarimovie-termux.sh)
if [ ! -f "$ROOT_DIR/vendor/autoload.php" ]; then
  bash "$ROOT_DIR/install-termux.sh"
fi

echo "Starting PencariMovie Downloader with FrankenPHP through proot..."
echo "Log file: $LOG_FILE"

# Use Unix-optimised php.ini (static build — no dynamic extension loading)
if [ -f "$ROOT_DIR/bin/php.ini.unix" ]; then
  cp "$ROOT_DIR/bin/php.ini.unix" "$ROOT_DIR/bin/php.ini"
fi

# ----- Start FrankenPHP through proot -----
# PHPRC must point to bin/ so FrankenPHP loads bin/php.ini (which was copied
# from bin/php.ini.unix above). Without PHPRC, static FrankenPHP builds may
# not find any php.ini, leaving display_errors=1 and breaking JSON responses.
proot --link2symlink -0 \
  -w "$ROOT_DIR" \
  -b "$ROOT_DIR:$ROOT_DIR" \
  -b "$TMP_DIR:/tmp" \
  -b "$TMP_DIR/resolv.conf:/etc/resolv.conf" \
  /bin/sh -c 'export PATH="$1/bin:$PATH"; export PHP_BINDIR="$1/bin"; export PHPRC="$1/bin"; exec "$2" php-server --listen "$3:$4" --root "$5"' \
  sh "$ROOT_DIR" "$FRANKENPHP_BIN" "$HOST" "$PORT" "$ROOT_DIR" >>"$LOG_FILE" 2>&1 &
PID="$!"

echo "$PID" > "$PID_FILE" 2>/dev/null || true

sleep 2

if ! kill -0 "$PID" 2>/dev/null; then
  echo "FrankenPHP exited during startup. Last log lines:"
  tail -n 50 "$LOG_FILE" 2>/dev/null || true
  exit 1
fi

echo ""
echo "PencariMovie Downloader is running"
echo "  Local:    http://127.0.0.1:$PORT"
echo "PID: $PID"
