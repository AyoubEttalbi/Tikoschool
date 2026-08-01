#!/bin/sh
set -e

mkdir -p /app/storage/fonts
mkdir -p /app/storage/framework/cache
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/logs

php /app/artisan storage:link || true

php /app/artisan migrate --force || true

if [ "${APP_ENV}" = "production" ] || [ "${APP_ENV}" = "prod" ]; then
    php /app/artisan config:cache
    php /app/artisan route:cache
    php /app/artisan view:cache
fi

chown -R www-data:www-data /app/storage /app/bootstrap/cache

echo "* * * * * php /app/artisan schedule:run >> /dev/null 2>&1" > /etc/crontabs/www-data

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
