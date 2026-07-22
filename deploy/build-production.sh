#!/usr/bin/env bash
# Chạy 1 lệnh này sau khi đã set xong .env production (bước 1 trong README) và đã pull code mới.
set -e

npm ci
npm run build
php artisan optimize
php artisan view:cache
