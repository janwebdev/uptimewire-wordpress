#!/usr/bin/env bash
# Builds the release folder build/uptimewire from the files not listed in .distignore,
# and the installable archive build/uptimewire-<version>.zip.
# The archive is reproducible: files in name order, every date set to the last commit's date,
# so the same commit always gives the same bytes (needs python3).
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/uptimewire
mkdir -p build/uptimewire
rsync -a --exclude-from=.distignore ./ build/uptimewire/
echo "build/uptimewire:"
(cd build/uptimewire && find . -type f | sort)

VERSION=$(sed -n "s/^define( 'UPTIMEWIRE_VERSION', '\([^']*\)' );/\1/p" uptimewire.php)
STAMP=$(git log -1 --format=%ct 2>/dev/null || echo 315532800)
rm -f build/uptimewire-*.zip
python3 - "build/uptimewire-$VERSION.zip" "$STAMP" <<'PY'
import os, sys, time, zipfile
target, stamp = sys.argv[1], max(int(sys.argv[2]), 315532800)
date = time.gmtime(stamp)[:6]
with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
	for root, dirs, files in os.walk('build/uptimewire'):
		dirs.sort()
		for name in sorted(files):
			path = os.path.join(root, name)
			info = zipfile.ZipInfo(os.path.relpath(path, 'build'), date)
			info.external_attr = 0o644 << 16
			info.compress_type = zipfile.ZIP_DEFLATED
			with open(path, 'rb') as handle:
				archive.writestr(info, handle.read())
PY
echo "build/uptimewire-$VERSION.zip"
sha256sum "build/uptimewire-$VERSION.zip"
