#!/usr/bin/env bash
# Builds the release folder build/uptimewire from the files not listed in .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/uptimewire
mkdir -p build/uptimewire
rsync -a --exclude-from=.distignore ./ build/uptimewire/
echo "build/uptimewire:"
(cd build/uptimewire && find . -type f | sort)
