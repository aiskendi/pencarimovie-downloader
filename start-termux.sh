#!/usr/bin/env bash
set -euo pipefail

print_banner() {
  [ -n "${PENCARIMOVIE_NO_BANNER:-}" ] && return 0
  local orange="" reset=""
  if [ -t 1 ]; then
    orange="$(printf '\033[38;5;208m')"
    reset="$(printf '\033[0m')"
  fi
  printf '%s' "$orange"
  cat <<'EOF'

 ========================================
          PencariMovie Server
 ========================================

EOF
  printf '%s' "$reset"
}

print_banner

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
FRANKENPHP_BIN="$ROOT_DIR/bin/frankenphp"
HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8088}"
TMP_DIR="$ROOT_DIR/tmp"
LOG_FILE="${LOG_FILE:-$ROOT_DIR/frankenphp.log}"
PID_FILE="$ROOT_DIR/.frankenphp.pid"

# Detect LAN IP outside proot. FrankenPHP's PATH is only bin/, so PHP cannot
# exec Termux ifconfig/getprop. Write the result for backend.php to read.
get_lan_ip() {
  local output=""
  if command -v ifconfig >/dev/null 2>&1; then
    output="$(ifconfig 2>/dev/null || true)"
  elif command -v ip >/dev/null 2>&1; then
    output="$(ip -4 addr show 2>/dev/null || true)"
  fi
  [ -z "$output" ] && return 0
  printf '%s\n' "$output" | awk '
    BEGIN { best = -1; skip = 1 }
    function set_iface(name) {
      iface = tolower(name)
      sub(/:$/, "", iface)
      sub(/@.*/, "", iface)
      skip = (iface == "lo" || iface ~ /^(rmnet|tun|wg|ppp|ccmni|pdp|clat|dummy|orichi|sit|ipsec)/)
      score = 40
      if (iface ~ /^(ap[0-9]*|softap[0-9]*)$/ || iface ~ /wlan[0-9]*_ap/) score = 100
      else if (iface ~ /^wlan[0-9]+/) score = 90
      else if (iface ~ /^(rndis|usb|eth|bnep)/) score = 70
      else if (iface ~ /^vgate/) score = 20
    }
    /^[0-9]+:\s+/ { set_iface($2); next }
    /^[A-Za-z0-9_.-]+/ { set_iface($1); next }
    skip { next }
    /inet / {
      for (i = 1; i <= NF; i++) {
        val = $i
        sub(/^addr:/, "", val)
        sub(/\/.*/, "", val)
        split(val, o, ".")
        if (o[1] == 10 || (o[1] == 172 && o[2] >= 16 && o[2] <= 31) || (o[1] == 192 && o[2] == 168)) {
          if (val != "127.0.0.1" && val !~ /^169\.254\./ && val !~ /^172\.17\./ && val !~ /^192\.168\.56\./) {
            if (score > best) { best = score; bestip = val }
          }
        }
      }
    }
    END { if (bestip != "") print bestip }
  '
}

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

echo "Starting PencariMovie Server with FrankenPHP through proot..."
echo "Log file: $LOG_FILE"

# Use Unix-optimised php.ini (static build — no dynamic extension loading)
if [ -f "$ROOT_DIR/bin/php.ini.unix" ]; then
  cp "$ROOT_DIR/bin/php.ini.unix" "$ROOT_DIR/bin/php.ini"
fi

LAN_IP="$(get_lan_ip || true)"
mkdir -p "$ROOT_DIR/storage"
if [ -n "${LAN_IP}" ]; then
  printf '%s\n' "$LAN_IP" > "$ROOT_DIR/storage/lan_ip.txt"
else
  rm -f "$ROOT_DIR/storage/lan_ip.txt"
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
  /bin/sh -c 'export PATH="$1/bin:$PATH"; export PHP_BINDIR="$1/bin"; export PHPRC="$1/bin"; export LAN_IP="$6"; exec "$2" php-server --listen "$3:$4" --root "$5"' \
  sh "$ROOT_DIR" "$FRANKENPHP_BIN" "$HOST" "$PORT" "$ROOT_DIR" "${LAN_IP:-}" >>"$LOG_FILE" 2>&1 &
PID="$!"

echo "$PID" > "$PID_FILE" 2>/dev/null || true

sleep 2

if ! kill -0 "$PID" 2>/dev/null; then
  echo "FrankenPHP exited during startup. Last log lines:"
  tail -n 50 "$LOG_FILE" 2>/dev/null || true
  exit 1
fi

echo ""
echo "PencariMovie Server is running"
echo "  Local:    http://127.0.0.1:$PORT"
if [ -n "${LAN_IP}" ]; then
  echo "  Network:  http://$LAN_IP:$PORT"
fi
echo "PID: $PID"
