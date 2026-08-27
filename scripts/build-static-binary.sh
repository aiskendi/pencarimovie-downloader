#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="pencarimovie-static"
CONTAINER_TEMP="pencarimovie-static-tmp"
OUTPUT_DIR="$(cd "$(dirname "$0")/.." && pwd)/dist"
OUTPUT_BIN="$OUTPUT_DIR/pencarimovie-static-linux-x86_64"

mkdir -p "$OUTPUT_DIR"

echo "Building standalone FrankenPHP image with embedded app..."
docker build -t "$IMAGE_NAME" -f static-build.Dockerfile .

echo "Extracting self-contained binary..."
docker create --name "$CONTAINER_TEMP" "$IMAGE_NAME"
docker cp "$CONTAINER_TEMP:/go/src/app/dist/frankenphp-linux-x86_64" "$OUTPUT_BIN"
docker rm "$CONTAINER_TEMP"

chmod +x "$OUTPUT_BIN"
echo "Build complete: $OUTPUT_BIN"
