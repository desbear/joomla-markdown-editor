#!/usr/bin/env bash
# Builds dist/pkg_gssmarkdown-<version>.zip, installable via System > Install > Extensions.
# Runtime PHP deps must already be installed: (cd src/libraries/gssmarkdown && composer install --no-dev)
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' src/pkg_gssmarkdown.xml | head -1)
OUT=dist
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

[ -f src/libraries/gssmarkdown/vendor/autoload.php ] || { echo "Run composer install --no-dev in src/libraries/gssmarkdown first" >&2; exit 1; }

zipdir() { (cd "$1" && zip -qrX "$2" . -x '*.DS_Store' -x 'composer.json' -x 'composer.lock'); }

mkdir -p "$STAGE/packages" "$OUT"
zipdir src/libraries/gssmarkdown       "$STAGE/packages/lib_gssmarkdown.zip"
zipdir src/plugins/system/gssmarkdown  "$STAGE/packages/plg_system_gssmarkdown.zip"
zipdir src/plugins/editors/gssmarkdown "$STAGE/packages/plg_editors_gssmarkdown.zip"
zipdir src/plugins/task/gssmarkdown    "$STAGE/packages/plg_task_gssmarkdown.zip"
cp src/pkg_gssmarkdown.xml src/script.php "$STAGE/"

rm -f "$OUT/pkg_gssmarkdown-$VERSION.zip"
(cd "$STAGE" && zip -qrX "$OLDPWD/$OUT/pkg_gssmarkdown-$VERSION.zip" .)
echo "$OUT/pkg_gssmarkdown-$VERSION.zip"
