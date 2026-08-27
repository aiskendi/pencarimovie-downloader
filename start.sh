#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
FRANKENPHP_BIN="$ROOT_DIR/bin/frankenphp"
HOST="0.0.0.0"
PORT="8088"

# Detect LAN IP via default route (avoids virtual adapter IPs like Docker/VPN)
LAN_IP=""

# WSL: use powershell.exe to get Windows host's real LAN IP via route print
if grep -qi microsoft /proc/version 2>/dev/null && command -v powershell.exe >/dev/null 2>&1; then
  LAN_IP=$(powershell.exe -Command "route print -4 0.0.0.0 | Select-String '0.0.0.0\s+0.0.0.0' | ForEach-Object { (\$_ -split '\s+')[4] }" 2>/dev/null | tr -d '\r' | head -1)
fi

# Standard Linux: use ip route get (avoids listing all adapters)
if [ -z "$LAN_IP" ] && command -v ip >/dev/null 2>&1; then
  LAN_IP=$(ip route get 8.8.8.8 2>/dev/null | grep -oP '(?<=src )[\d.]+' | head -1)
fi

# Fallback: hostname -I
if [ -z "$LAN_IP" ] && command -v hostname >/dev/null 2>&1; then
  LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
fi

print_urls() {
  echo ""
  echo "  Local:    http://127.0.0.1:$PORT"
  if [ -n "$LAN_IP" ]; then
    echo "  Network:  http://$LAN_IP:$PORT"
    echo ""
    echo "  Other devices on your network can connect using the Network URL above."
  fi
  echo ""
}

if [ -x "$FRANKENPHP_BIN" ]; then
  echo "Starting PencariMovie Downloader with FrankenPHP..."
  export PATH="$ROOT_DIR/bin:$PATH"
  export PHP_BINDIR="$ROOT_DIR/bin"
  export PHPRC="$ROOT_DIR/bin"
  nohup "$FRANKENPHP_BIN" php-server --listen "$HOST:$PORT" --root "$ROOT_DIR" >/dev/null 2>&1 &
  echo $! > "$ROOT_DIR/.frankenphp.pid"
  print_urls
  echo "FrankenPHP server started (PID $(cat "$ROOT_DIR/.frankenphp.pid"))."
  exit 0
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP or FrankenPHP is required but was not found."
  echo "Place FrankenPHP at $FRANKENPHP_BIN or install PHP in PATH."
  exit 1
fi
echo "Starting PencariMovie Downloader with PHP..."
nohup php -S "$HOST:$PORT" "$ROOT_DIR/router.php" >/dev/null 2>&1 &
echo $! > "$ROOT_DIR/.php-server.pid"
print_urls
echo "PHP server started (PID $(cat "$ROOT_DIR/.php-server.pid"))."
