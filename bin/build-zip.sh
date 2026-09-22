#!/usr/bin/env bash
# Construye build-zip/voceador.zip con el contenido distribuible según .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build-zip
mkdir -p build-zip/voceador
rsync -a --exclude-from=.distignore --exclude=build-zip ./ build-zip/voceador/
( cd build-zip && zip -qr voceador.zip voceador )
echo "build-zip/voceador.zip ($(du -h build-zip/voceador.zip | cut -f1))"
