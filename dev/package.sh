#!/usr/bin/env bash
# Copyright (C) 2026 FacturX API - GPL-3.0-or-later
# Builds dist/module_facturxverify-<version>.zip, the layout DoliStore and
# "Setup > Modules > Deploy external module" expect (one top folder: facturxverify/).
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
VERSION=$(grep -oE "\\\$this->version = '[^']+'" "$ROOT/facturxverify/core/modules/modFacturxVerify.class.php" | sed "s/.*'\(.*\)'/\1/")
mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/module_facturxverify-$VERSION.zip"
rm -f "$OUT"
cp "$ROOT/LICENSE" "$ROOT/facturxverify/COPYING"
(cd "$ROOT" && zip -qr -X "$OUT" facturxverify -x '*/.*')
rm -f "$ROOT/facturxverify/COPYING"
unzip -l "$OUT" | tail -1
echo "$OUT"
