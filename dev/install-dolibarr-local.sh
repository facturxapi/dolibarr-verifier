#!/usr/bin/env bash
# Install a throwaway Dolibarr on PHP's built-in server + a local MySQL/MariaDB,
# without Docker. Used when Docker Hub is not reachable; CI uses docker-compose.yml.
# Usage: DOLI_SRC=/path/to/dolibarr DOLI_DATA=/tmp/dolidata PORT=8080 dev/install-dolibarr-local.sh
set -euo pipefail
DOLI_SRC=${DOLI_SRC:?path to a Dolibarr checkout}
DOLI_DATA=${DOLI_DATA:-/tmp/dolidata}
PORT=${PORT:-8080}
DB_NAME=${DB_NAME:-dolibarr} DB_USER=${DB_USER:-doli} DB_PASS=${DB_PASS:-doli}
ADMIN_PASS=${ADMIN_PASS:-admin1234}
URL="http://127.0.0.1:${PORT}"
HT="$DOLI_SRC/htdocs"
rm -f "$DOLI_DATA/install.lock"; mkdir -p "$DOLI_DATA"
: > "$HT/conf/conf.php"; chmod 666 "$HT/conf/conf.php"
cat > "$HT/install/install.forced.php" <<PHP
<?php
\$force_install_noedit = 0;
\$force_install_main_data_root = '$DOLI_DATA';
\$force_install_mainforcehttps = false;
\$force_install_database = '$DB_NAME';
\$force_install_type = 'mysqli';
\$force_install_dbserver = '127.0.0.1';
\$force_install_port = 3306;
\$force_install_prefix = 'llx_';
\$force_install_createdatabase = false;
\$force_install_databaselogin = '$DB_USER';
\$force_install_databasepass = '$DB_PASS';
\$force_install_createuser = false;
\$force_install_dolibarrlogin = 'admin';
PHP
pgrep -f "php -S 127.0.0.1:${PORT}" >/dev/null || (cd "$HT" && nohup php -d memory_limit=512M -S 127.0.0.1:${PORT} > "$DOLI_DATA/php-server.log" 2>&1 &)
sleep 1
post() { curl -sS -o /dev/null -w "%{http_code} $1\n" -X POST "$URL/install/$1" --data "$2"; }
post step1.php "testpost=ok&action=set&selectlang=fr_FR&main_dir=$HT&main_data_dir=$DOLI_DATA&main_url=$URL&db_type=mysqli&db_host=127.0.0.1&db_port=3306&db_name=$DB_NAME&db_prefix=llx_&db_user=$DB_USER&db_pass=$DB_PASS"
post step2.php "testpost=ok&action=set&dolibarr_main_db_character_set=utf8mb4&dolibarr_main_db_collation=utf8mb4_unicode_ci&selectlang=fr_FR"
post step4.php "testpost=ok&action=set&selectlang=fr_FR"
post step5.php "testpost=ok&action=set&selectlang=fr_FR&login=admin&pass=$ADMIN_PASS&pass_verif=$ADMIN_PASS"
touch "$DOLI_DATA/install.lock"
echo "Dolibarr ready on $URL (admin / $ADMIN_PASS)"
