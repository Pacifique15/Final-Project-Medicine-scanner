#!/bin/sh
cd "$(dirname "$0")"
if command -v php >/dev/null 2>&1; then
  echo "Starting with PHP authentication router: http://localhost:8000/"
  php -S 0.0.0.0:8000 auth_router.php
else
  echo "PHP is required because the website now requires authenticated sessions."
  echo "Install PHP and enable pdo_sqlite."
fi
