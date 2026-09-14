#!/usr/bin/env bash
# Negative real-container checks: initialization must never proceed with missing config or a failed DB.
set -euo pipefail
if docker run --rm complyflow-ai:test > /dev/null 2>&1; then
  echo 'FAIL: missing secrets accepted' >&2; exit 1
fi
export APP_KEY_MATERIAL="$(openssl rand -hex 32)"
export PROCESSOR_HMAC_SECRET="$(openssl rand -hex 32)"
if docker run --rm -e APP_KEY_MATERIAL -e PROCESSOR_HMAC_SECRET -e APP_URL=http://localhost \
    -e PORT=invalid complyflow-ai:test > /dev/null 2>&1; then
  echo 'FAIL: invalid port accepted' >&2; exit 1
fi
if output=$(docker run --rm -e APP_KEY_MATERIAL -e PROCESSOR_HMAC_SECRET -e APP_URL=http://localhost \
    -e DB_URL=postgres://test:never-log-this-password@127.0.0.1:1/test complyflow-ai:test 2>&1); then
  echo 'FAIL: unavailable database accepted' >&2; exit 1
fi
case "$output" in
  *never-log-this-password*|*"$APP_KEY_MATERIAL"*|*"$PROCESSOR_HMAC_SECRET"*)
    echo 'FAIL: initialization leaked secret' >&2; exit 1;;
esac
case "$output" in
  *'Application initialization failed;'*) :;;
  *) echo 'FAIL: unexpected initialization failure' >&2; exit 1;;
esac
echo 'PASS: startup rejects missing config, malformed port and failed DB without secrets'
