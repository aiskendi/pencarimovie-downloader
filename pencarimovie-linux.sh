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

APP_DIR="pencarimovie-server"
OLD_APP_DIR="pencarimovie-downloader"
PORT="${PORT:-8088}"
HOST="${HOST:-0.0.0.0}"
REPO="aiskendi/pencarimovie-downloader"
FALLBACK_TAG="v1.0.0"

detect_target() {
  local arch os
  arch="$(uname -m)"
  os="$(uname -s)"
  case "$os" in
    Linux)
      case "$arch" in
        x86_64|amd64)  echo "linux-x86_64" ;;
        aarch64|arm64) echo "linux-aarch64" ;;
        *) echo "Unsupported: $arch"; exit 1 ;;
      esac
      ;;
    Darwin)
      case "$arch" in
        arm64|aarch64) echo "mac-arm64" ;;
        x86_64|amd64)  echo "mac-x86_64" ;;
        *) echo "Unsupported: $arch"; exit 1 ;;
      esac
      ;;
    *) echo "Unsupported OS: $os"; exit 1 ;;
  esac
}

usage() {
  echo "Usage: $0 [--start|--stop|--restart]"
  exit 1
}

get_lan_ip() {
  local ip=""
  if command -v ip >/dev/null 2>&1; then
    ip="$(ip route get 8.8.8.8 2>/dev/null | grep -oP '(?<=src )[\d.]+' | head -1)"
  fi
  if [ -z "$ip" ] && command -v hostname >/dev/null 2>&1; then
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  echo "$ip"
}

print_urls() {
  local lan_ip
  lan_ip="$(get_lan_ip)"
  echo "  Local:    http://127.0.0.1:$PORT"
  [ -n "$lan_ip" ] && echo "  Network:  http://$lan_ip:$PORT"
}

port_in_use() {
  if command -v curl >/dev/null 2>&1; then
    curl -s -o /dev/null http://127.0.0.1:"$PORT" 2>/dev/null && return 0
  fi
  if command -v wget >/dev/null 2>&1; then
    wget -q -O /dev/null http://127.0.0.1:"$PORT" 2>/dev/null && return 0
  fi
  return 1
}

do_stop() {
  echo "Stopping PencariMovie Server..."

  local pid=""
  [ -f "$APP_DIR/.frankenphp.pid" ] && pid="$(cat "$APP_DIR/.frankenphp.pid" 2>/dev/null || true)"
  [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  pkill -f "frankenphp.*php-server" 2>/dev/null || true
  fuser -k "$PORT"/tcp 2>/dev/null || true

  rm -f "$APP_DIR/.frankenphp.pid"
  echo "Server stopped."
}

download_file() {
  local url="$1" dest="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -L --fail -o "$dest" "$url"
  elif command -v wget >/dev/null 2>&1; then
    wget -O "$dest" "$url"
  else
    echo "Need curl or wget."; exit 1
  fi
}

# Follow GitHub /releases/latest and return the tag (e.g. v1.0.1).
fetch_latest_tag() {
  local loc=""
  if command -v curl >/dev/null 2>&1; then
    loc="$(curl -fsSL -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest" 2>/dev/null || true)"
  elif command -v wget >/dev/null 2>&1; then
    loc="$(wget -q --max-redirect=0 --server-response "https://github.com/$REPO/releases/latest" -O /dev/null 2>&1 \
      | awk 'BEGIN{IGNORECASE=1} /^  Location:/{print $2; exit}' | tr -d '\r' || true)"
  fi
  loc="${loc%$'\r'}"
  loc="${loc%/}"
  local tag="${loc##*/}"
  case "$tag" in
    v[0-9]*) echo "$tag" ;;
    *) return 1 ;;
  esac
}

current_tag() {
  if [ -f "$APP_DIR/.release-tag" ]; then
    tr -d '\r\n' < "$APP_DIR/.release-tag"
  fi
}

find_release_root() {
  local extract_dir="$1"
  if [ -f "$extract_dir/backend.php" ] || [ -f "$extract_dir/start.sh" ]; then
    echo "$extract_dir"
    return
  fi
  local found=""
  found="$(find "$extract_dir" -maxdepth 2 -type f \( -name backend.php -o -name start.sh \) 2>/dev/null | head -1 || true)"
  if [ -n "$found" ]; then
    dirname "$found"
    return
  fi
  echo "$extract_dir"
}

# Copy a extracted release into APP_DIR without touching existing storage/.
migrate_legacy_dir() {
  if [ -d "$OLD_APP_DIR" ] && [ "$OLD_APP_DIR" != "$APP_DIR" ]; then
    if [ -d "$OLD_APP_DIR/storage" ] && [ ! -d "$APP_DIR/storage" ]; then
      mkdir -p "$APP_DIR"
      cp -R "$OLD_APP_DIR/storage" "$APP_DIR/storage"
    fi
    rm -rf "$OLD_APP_DIR"
  fi
}

copy_release_into_app() {
  local src="$1" item name
  mkdir -p "$APP_DIR"
  for item in "$src"/*; do
    [ -e "$item" ] || continue
    name="$(basename "$item")"
    if [ "$name" = "storage" ]; then
      mkdir -p "$APP_DIR/storage"
      continue
    fi
    rm -rf "$APP_DIR/$name"
    cp -R "$item" "$APP_DIR/$name"
  done
}

strip_crlf() {
  local dir="${1:-.}" f
  for f in "$dir"/*.sh; do
    [ -f "$f" ] || continue
    tr -d '\r' < "$f" > "$f.tmp" && mv "$f.tmp" "$f"
  done
}

download_extract() {
  local target="$1" tag="$2"
  local url="https://github.com/$REPO/releases/download/$tag/pencarimovie-downloader-$target.tar.gz"
  local tmp src

  tmp="${TMPDIR:-/tmp}/pencarimovie-ota-$$"
  rm -rf "$tmp"
  mkdir -p "$tmp/extract"

  echo "Downloading $url"
  download_file "$url" "$tmp/pencarimovie.tar.gz"
  tar -xzf "$tmp/pencarimovie.tar.gz" -C "$tmp/extract"
  src="$(find_release_root "$tmp/extract")"
  copy_release_into_app "$src"
  strip_crlf "$APP_DIR"
  printf '%s\n' "$tag" > "$APP_DIR/.release-tag"
  rm -rf "$tmp"
}

# Returns 0 if files were installed/updated, 1 if already up to date.
install_or_update() {
  local target latest current
  target="$(detect_target)"
  latest="$(fetch_latest_tag || true)"
  current="$(current_tag)"

  if [ -d "$APP_DIR" ] && [ -z "$current" ]; then
    current="$FALLBACK_TAG"
    printf '%s\n' "$current" > "$APP_DIR/.release-tag"
  fi

  if [ -z "$latest" ]; then
    if [ -d "$APP_DIR" ]; then
      echo "Could not check GitHub for updates; using installed copy."
      return 1
    fi
    latest="$FALLBACK_TAG"
  fi

  if [ -d "$APP_DIR" ] && [ "$current" = "$latest" ]; then
    return 1
  fi

  if [ ! -d "$APP_DIR" ]; then
    echo "Downloading PencariMovie Server $latest ($target)..."
  else
    echo "Updating PencariMovie Server ${current:-unknown} -> $latest ($target)..."
    if port_in_use; then
      do_stop
      sleep 1
    fi
  fi

  download_extract "$target" "$latest"
  return 0
}

do_start() {
  migrate_legacy_dir

  local had_app=0 updated=0
  [ -d "$APP_DIR" ] && had_app=1

  if install_or_update; then
    updated=1
  fi

  if port_in_use; then
    if [ "$had_app" -eq 1 ] && [ "$updated" -eq 0 ]; then
      echo "Server is already running on port $PORT."
      print_urls
      echo "  Use --stop to stop or --restart to restart."
      return
    fi
    echo "Port $PORT is already in use; stopping leftover process..."
    do_stop
    sleep 1
  fi

  cd "$APP_DIR"
  strip_crlf "."
  chmod u+x bin/frankenphp start.sh 2>/dev/null || true
  PENCARIMOVIE_NO_BANNER=1 bash start.sh
}

do_restart() { do_stop; sleep 1; do_start; }

case "${1:-}" in
  start|--start|"") do_start ;;
  stop|--stop) do_stop ;;
  restart|--restart) do_restart ;;
  *) usage ;;
esac
