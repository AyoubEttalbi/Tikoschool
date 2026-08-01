#!/bin/sh
set -e

mkdir -p /app/storage/fonts
mkdir -p /app/storage/framework/cache
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/logs

php /app/artisan storage:link || true

# `|| true` was here and swallowed migration failures, so a container would happily boot
# and serve traffic against a half-migrated schema. `set -e` at the top now aborts startup
# instead — a failed deploy is far better than silent data corruption.
php /app/artisan migrate --force

if [ "${APP_ENV}" = "production" ] || [ "${APP_ENV}" = "prod" ]; then
    php /app/artisan config:clear
    php /app/artisan config:cache
    php /app/artisan route:cache
    php /app/artisan view:cache
    # opcache runs with validate_timestamps=0, so stale bytecode would otherwise survive
    # a deploy; queue:restart tells any running worker to pick up the new code.
    php /app/artisan queue:restart || true
fi

chown -R www-data:www-data /app/storage /app/bootstrap/cache

echo "* * * * * php /app/artisan schedule:run >> /dev/null 2>&1" > /etc/crontabs/www-data

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
