#!/usr/bin/env bash
# Copyright (C) 2026 FacturX API - GPL-3.0-or-later
#
# The whole test suite, exactly as CI runs it (and as it can be run on a laptop):
#   1. php -l on every file, 2. unit tests, 3. a real Dolibarr (given tag) installed on
#   PHP's built-in server + MySQL/MariaDB, the module enabled, an invoice created, and the
#   check / repair flow driven through Dolibarr's own pages against the FacturX API test double.
#
#   DOLI_VERSION=22.0.5 DB_HOST=127.0.0.1 DB_ROOT_PASS=root dev/ci.sh
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DOLI_VERSION=${DOLI_VERSION:-22.0.5}
WORK=${WORK:-$(mktemp -d)}
PORT=${PORT:-8080}
MOCK_PORT=${MOCK_PORT:-8765}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_ROOT_PASS=${DB_ROOT_PASS:-}
DB_NAME=${DB_NAME:-doli_$(echo "$DOLI_VERSION" | tr . _)}
MYSQL_ROOT=${MYSQL_ROOT:-"mysql -h$DB_HOST -uroot ${DB_ROOT_PASS:+-p$DB_ROOT_PASS}"}
export MOCK_LOG="$WORK/mock.log"

echo "== 1. lint"
find "$ROOT/facturxverify" "$ROOT/tests" -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true
if find "$ROOT/facturxverify" "$ROOT/tests" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -q 'Parse error'; then exit 1; fi

echo "== 2. unit"
php "$ROOT/tests/unit/run.php"

echo "== 3. Dolibarr $DOLI_VERSION"
rm -rf "$WORK/dolidata"
[ -d "$WORK/dolibarr" ] || git clone -q --depth 1 --branch "$DOLI_VERSION" https://github.com/Dolibarr/dolibarr.git "$WORK/dolibarr" 2>/dev/null
$MYSQL_ROOT -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'doli'@'%' IDENTIFIED BY 'doli'; CREATE USER IF NOT EXISTS 'doli'@'localhost' IDENTIFIED BY 'doli';
  GRANT ALL ON $DB_NAME.* TO 'doli'@'%'; GRANT ALL ON $DB_NAME.* TO 'doli'@'localhost'; FLUSH PRIVILEGES;"
DOLI_SRC="$WORK/dolibarr" DOLI_DATA="$WORK/dolidata" PORT=$PORT DB_NAME=$DB_NAME "$ROOT/dev/install-dolibarr-local.sh"
mkdir -p "$WORK/dolibarr/htdocs/custom"
ln -sfn "$ROOT/facturxverify" "$WORK/dolibarr/htdocs/custom/facturxverify"
(cd "$ROOT" && nohup php -S 127.0.0.1:$MOCK_PORT tests/mock-api/router.php > "$WORK/mock-server.log" 2>&1 &)
sleep 1
OUT=$(php "$ROOT/tests/integration/bootstrap.php" "$WORK/dolibarr/htdocs" "http://127.0.0.1:$MOCK_PORT" fx_mock_key | tail -1)
echo "$OUT"
ID=$(echo "$OUT" | sed 's/.*INVOICE_ID=\([0-9]*\).*/\1/')
DIR=$(echo "$OUT" | sed 's/.*DIR=//')
DOLI_URL="http://127.0.0.1:$PORT" MYSQL="$MYSQL_ROOT $DB_NAME" "$ROOT/tests/integration/run.sh" "$ID" "$DIR"

echo "== 4. PHP warnings raised by the module"
if grep -E "PHP (Warning|Fatal|Parse)" "$WORK/dolidata/php-server.log" | grep -i facturxverify; then exit 1; fi
echo "none"
echo "CI OK on Dolibarr $DOLI_VERSION ($(php -r 'echo PHP_VERSION;'))"
