#!/bin/sh

set -eu

app_dir=/workspace/apps/api
env_file="$app_dir/.env"

if [ ! -f "$env_file" ]; then
    cp "$app_dir/.env.example" "$env_file"
fi

if ! grep -Eq '^APP_KEY=.+$' "$env_file"; then
    (cd "$app_dir" && php artisan key:generate --no-interaction)
fi

exec docker-php-entrypoint "$@"
