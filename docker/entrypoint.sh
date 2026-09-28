#!/usr/bin/env bash
# Waits for MySQL, migrates, seeds on first boot, then hands over to the CMD.
set -euo pipefail

cd /var/www/html

# The image ships no .env file (compose injects the environment), so
# `artisan key:generate` cannot write anywhere. Generate the key in-process and
# keep it on a mounted volume so sessions survive restarts. Set APP_KEY in the
# project .env to pin it explicitly.
KEY_FILE="${CHAMMA_APP_KEY_FILE:-/var/www/html/storage/keys/app.key}"
if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "base64:" ]; then
    mkdir -p "$(dirname "$KEY_FILE")"
    if [ ! -s "$KEY_FILE" ]; then
        echo "[chamma] generating APP_KEY"
        printf 'base64:%s' "$(head -c 32 /dev/urandom | base64)" > "$KEY_FILE"
        chmod 600 "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

echo "[chamma] waiting for database"
until php artisan db:monitor >/dev/null 2>&1; do
    sleep 2
done

echo "[chamma] clearing stale cache"
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

echo "[chamma] migrating"
php artisan migrate --force --no-interaction

# The seeder is idempotent, so it is safe to run on every boot and guarantees
# `docker compose up -d` always yields a browsable, non-empty store.
if [ "${CHAMMA_SEED_ON_BOOT:-true}" = "true" ]; then
    echo "[chamma] seeding"
    php artisan db:seed --force --no-interaction
fi

if [ "${CHAMMA_ARTWORK_ON_BOOT:-true}" = "true" ]; then
    echo "[chamma] verifying generated artwork"
    php artisan artwork:check --quiet || echo "[chamma] artwork check reported issues (see logs)"
fi

echo "[chamma] ready"
exec "$@"
