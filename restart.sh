#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"

"${BASH:-bash}" "$ROOT_DIR/stop.sh"
echo "Restarting PencariMovie Downloader..."
"${BASH:-bash}" "$ROOT_DIR/start.sh"
