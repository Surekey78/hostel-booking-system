#!/usr/bin/env bash
# setup.sh — one-command bootstrap for the sandbox / a fresh Linux box.
# Creates the DB + user, imports schema & demo data, then starts the dev server.
set -e
cd "$(dirname "$0")"

DB_ROOT_CMD="${DB_ROOT_CMD:-sudo mysql}"

echo "==> Creating database + user (hostel_booking / hostel_user)"
$DB_ROOT_CMD -e "CREATE DATABASE IF NOT EXISTS hostel_booking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER IF NOT EXISTS 'hostel_user'@'localhost' IDENTIFIED BY 'Hostel@2026';
                 GRANT ALL PRIVILEGES ON hostel_booking.* TO 'hostel_user'@'localhost';
                 FLUSH PRIVILEGES;"

echo "==> Importing schema"
$DB_ROOT_CMD hostel_booking < database/schema.sql

echo "==> Importing demo data"
php database/seed.php

echo
echo "==> Starting server on http://0.0.0.0:8080"
exec php -S 0.0.0.0:8080 router.php
