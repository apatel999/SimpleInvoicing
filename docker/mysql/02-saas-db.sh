#!/bin/bash
set -euo pipefail
saas_db="${SAAS_DATABASE:-invoicehost}"
mysql -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "CREATE DATABASE IF NOT EXISTS \`${saas_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${saas_db}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;"
