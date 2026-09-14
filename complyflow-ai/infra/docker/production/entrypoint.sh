#!/bin/sh
set -eu
umask 077
cd /app/apps/api
: "${PROCESSOR_HMAC_SECRET:?Set PROCESSOR_HMAC_SECRET}"
: "${APP_KEY_MATERIAL:?Set stable, randomly generated APP_KEY_MATERIAL}"
export APP_KEY="$(php -r 'echo "base64:".base64_encode(hash("sha256", getenv("APP_KEY_MATERIAL"), true));')"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
: "${APP_URL:?Set APP_URL or use Render RENDER_EXTERNAL_URL}"
case "${PORT:-10000}" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then echo 'Invalid PORT' >&2; exit 1; fi
php /etc/complyflow/bootstrap.php
sed "s/__PORT__/$PORT/g" /etc/complyflow/nginx.conf > /tmp/complyflow-nginx.conf
nginx -t -c /tmp/complyflow-nginx.conf
exec /usr/bin/supervisord -c /etc/complyflow/supervisord.conf
