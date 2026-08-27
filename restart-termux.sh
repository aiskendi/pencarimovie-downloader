#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

"${BASH:-bash}" "$ROOT_DIR/stop.sh"
echo "Restarting PencariMovie Downloader through Termux/proot..."
"${BASH:-bash}" "$ROOT_DIR/start-termux.sh"

