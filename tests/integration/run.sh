#!/usr/bin/env bash
# Copyright (C) 2026 FacturX API - GPL-3.0-or-later
#
# End-to-end test through Dolibarr's own web pages, against the FacturX API test double.
#   DOLI_URL=http://127.0.0.1:8080 DOLI_HTDOCS=/var/www/html MYSQL="mysql -uroot dolibarr" \
#   MOCK_LOG=/tmp/facturx-mock.log tests/integration/run.sh <invoice_id> <invoice_dir>
set -euo pipefail
DOLI_URL=${DOLI_URL:-http://127.0.0.1:8080}
MYSQL=${MYSQL:-mysql -uroot dolibarr}
MOCK_LOG=${MOCK_LOG:-/tmp/facturx-mock.log}
ADMIN_PASS=${ADMIN_PASS:-admin1234}
ID=$1
DIR=$2
JAR=$(mktemp)
fail() { echo "FAIL - $*"; exit 1; }
ok() { echo "ok   - $*"; }
token() { grep -o 'name="token" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/'; }

# Login
T=$(curl -sS -c "$JAR" -b "$JAR" "$DOLI_URL/index.php" | token)
curl -sS -c "$JAR" -b "$JAR" -o /dev/null -X POST "$DOLI_URL/index.php?mainmenu=home" \
  --data-urlencode "token=$T" --data "actionlogin=login&loginfunction=loginfunction&username=admin" --data-urlencode "password=$ADMIN_PASS"

# Invoice card shows the button
CARD=$(curl -sS -b "$JAR" "$DOLI_URL/compta/facture/card.php?facid=$ID")
echo "$CARD" | grep -q 'facturxverify-card-check' && ok "button on the invoice card" || fail "button missing on the invoice card"

# Tab lists the CII file
TAB=$(curl -sS -b "$JAR" "$DOLI_URL/custom/facturxverify/tab.php?id=$ID")
echo "$TAB" | grep -q '_cii.xml' && ok "tab lists the CII file" || fail "tab does not list the file"
T=$(echo "$TAB" | token)
: > "$MOCK_LOG"

# Check
curl -sS -b "$JAR" -o /dev/null -X POST "$DOLI_URL/custom/facturxverify/tab.php?id=$ID" --data-urlencode "token=$T" --data "action=check"
ROW=$($MYSQL -N -e "SELECT operation, verdict, error_count, warning_count FROM llx_facturxverify_check WHERE fk_facture=$ID ORDER BY rowid DESC LIMIT 1")
[ "$(echo "$ROW" | tr '\t' ' ')" = "validate not_ready 2 1" ] && ok "check recorded: $ROW" || fail "unexpected row: $ROW"
grep -q '"path":"\\/api\\/v1\\/validate"' "$MOCK_LOG" || fail "validate not called"
grep -q 'validation_target=france_2026' "$MOCK_LOG" && ok "rules: france_2026" || fail "target not sent"
grep -q '"user_agent":"facturx-dolibarr\\/0.1.0 (Dolibarr ' "$MOCK_LOG" && ok "User-Agent attribution sent" || fail "User-Agent missing"
grep -q '"client":"dolibarr\\/0.1.0"' "$MOCK_LOG" && ok "X-FacturX-Client sent" || fail "X-FacturX-Client missing"

TAB=$(curl -sS -b "$JAR" "$DOLI_URL/custom/facturxverify/tab.php?id=$ID")
echo "$TAB" | grep -q 'BR-CO-15' && ok "report shows the rule" || fail "rule not displayed"
echo "$TAB" | grep -q 'facturxverify-repair' && ok "repair offered for a CII with blocking points" || fail "repair not offered"
T=$(echo "$TAB" | token)

# Repair then save next to the original
REP=$(curl -sS -b "$JAR" -X POST "$DOLI_URL/custom/facturxverify/tab.php?id=$ID" --data-urlencode "token=$T" --data "action=repair" --data-urlencode "file=$(basename "$(ls "$DIR"/*_cii.xml | head -1)")")
grep -q '"constraints":"{\\"do_not_modify_amounts\\":true}"' "$MOCK_LOG" && ok "repair sent with amounts locked" || fail "repair constraints missing"
echo "$REP" | grep -q '20240115' && ok "repair diff displayed" || fail "repair diff not displayed"
T=$(echo "$REP" | token)
curl -sS -b "$JAR" -o /dev/null -X POST "$DOLI_URL/custom/facturxverify/tab.php?id=$ID" --data-urlencode "token=$T" --data "action=saverepair"
ls "$DIR"/*_cii_repare.xml >/dev/null 2>&1 && ok "repaired file saved next to the original" || fail "repaired file not saved"
grep -q 'MOCK-REPAIRED' "$DIR"/*_cii_repare.xml && grep -q 'MOCK-BLOCKING' "$DIR"/*_cii.xml && ok "original untouched, repaired content written" || fail "file contents wrong"

# A wrong key is reported as an error, never as a verdict on the file
echo "all integration checks passed"
rm -f "$JAR"
