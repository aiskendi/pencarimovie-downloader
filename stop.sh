#!/usr/bin/env bash
set -euo pipefail

HOST="0.0.0.0"
PORT="8088"
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Stopping PencariMovie Server on $HOST:$PORT..."

for PID_FILE in "$ROOT_DIR/.frankenphp.pid" "$ROOT_DIR/.php-server.pid"; do
  if [ -f "$PID_FILE" ]; then
    PID="$(cat "$PID_FILE" 2>/dev/null || true)"
    if [ -n "${PID:-}" ] && kill -0 "$PID" 2>/dev/null; then
      echo "Killing process from $(basename "$PID_FILE") PID $PID"
      kill "$PID" 2>/dev/null || true
    fi
    rm -f "$PID_FILE" 2>/dev/null || true
  fi
done

if command -v lsof >/dev/null 2>&1; then
  PIDS="$(lsof -ti tcp:"$PORT" -sTCP:LISTEN || true)"
elif command -v fuser >/dev/null 2>&1; then
  PIDS="$(fuser "$PORT"/tcp 2>/dev/null || true)"
else
  echo "Install lsof or fuser to stop by port automatically."
  exit 1
fi

if [ -z "${PIDS:-}" ]; then
  echo "No process is listening on $HOST:$PORT."
  exit 0
fi

echo "$PIDS" | tr ' ' '\n' | while read -r PID; do
  [ -z "$PID" ] && continue
  echo "Killing process PID $PID"
  kill "$PID" 2>/dev/null || true
done

echo "Stop command completed."
