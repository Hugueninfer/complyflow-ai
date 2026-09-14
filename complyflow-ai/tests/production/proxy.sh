#!/usr/bin/env bash
set -euo pipefail
# Real Nginx -> FPM -> Laravel, HTTP backend simulates TLS terminated upstream.
# Explicitly scoped disposable container and fixture; no diagnostic endpoint in shipped image.
trap 'docker rm -f complyflow-proxy-probe >/dev/null 2>&1 || true' EXIT
docker run --rm -d --name complyflow-proxy-probe --network complyflow-production-smoke_default \
  --memory 512m --cpus 0.1 -p 127.0.0.1:18081:10000 \
  -v "$PWD/tests/production/proxy-probe.php:/app/apps/api/public/index.php:ro" \
  -e APP_KEY_MATERIAL="$SMOKE_APP_KEY_MATERIAL" -e PROCESSOR_HMAC_SECRET="$SMOKE_HMAC_SECRET" \
  -e APP_URL=https://portfolio.example.invalid -e SESSION_SECURE_COOKIE=true \
  -e DB_URL=postgres://smoke:local-smoke-only@postgres:5432/smoke complyflow-ai:test >/dev/null
for attempt in $(seq 1 90); do
  if curl --silent --fail http://127.0.0.1:18081/api/health >/dev/null; then break; fi
  sleep 1
done
python3 - <<'PY'
import json
import urllib.request
request = urllib.request.Request('http://127.0.0.1:18081/api/proxy-probe', headers={
    'Accept': 'application/json', 'Host': 'attacker.invalid', 'Origin': 'https://attacker.invalid',
    'Forwarded': 'for=203.0.113.99;proto=http;host=attacker.invalid',
    'X-Forwarded-For': '203.0.113.99', 'X-Forwarded-Proto': 'http',
    'X-Forwarded-Host': 'attacker.invalid', 'X-Forwarded-Port': '81',
})
with urllib.request.urlopen(request, timeout=10) as response:
    payload = json.load(response)
    assert payload['secure'] is True, payload
    assert payload['origin'] == 'https://portfolio.example.invalid', payload
    assert payload['login_url'] == 'https://portfolio.example.invalid/login', payload
    assert all(payload[key] is None for key in ['forwarded', 'forwarded_for', 'forwarded_host', 'forwarded_proto']), payload
    assert payload['ip'] != '203.0.113.99', payload
    session = next(c for c in response.headers.get_all('Set-Cookie') if 'httponly' in c.lower())
    assert '; secure' in session.lower() and 'samesite=lax' in session.lower(), session
print('PASS: canonical HTTPS/host/URLs, Secure session cookie and forged proxy headers stripped through real FastCGI')
PY
