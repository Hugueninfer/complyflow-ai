#!/usr/bin/env bash
# Dedicated disposable project: never points at the developer or Render database.
set -euo pipefail
export SMOKE_APP_KEY_MATERIAL="$(openssl rand -hex 32)"
export SMOKE_HMAC_SECRET="$(openssl rand -hex 32)"
compose=(docker compose -p complyflow-production-smoke -f infra/docker/production/compose.smoke.yaml)
cleanup() { "${compose[@]}" down -v; }
trap cleanup EXIT
"${compose[@]}" up -d --build --wait --wait-timeout 180
bash tests/production/entrypoint.sh
bash tests/production/proxy.sh
python3 tests/production/smoke.py
snapshot_sql="SELECT md5(string_agg(document_id::text || ':' || md5(contents::text), ',' ORDER BY document_id)) FROM document_blobs; SELECT public_id FROM organizations WHERE slug = 'demo-template';"
before_restart=$("${compose[@]}" exec -T postgres psql -U smoke -d smoke -Atc "$snapshot_sql")
# Runtime cache prefix is derived from the image's fixed APP_NAME="ComplyFlow AI".
"${compose[@]}" exec -T postgres psql -U smoke -d smoke -v ON_ERROR_STOP=1 -c "INSERT INTO cache (key, value, expiration) VALUES ('complyflow-ai-cache-gc-smoke-expired', 'expired-probe', 0), ('complyflow-ai-cache-gc-smoke-active', 'active-probe', extract(epoch from now())::bigint + 3600), ('unrelated-app-cache-old', 'foreign-probe', 0); INSERT INTO cache_locks (key, owner, expiration) VALUES ('complyflow-ai-cache-gc-smoke-lock', 'test-owner', 0);"
# Restart reruns migrations and seed under the database lock. Data must survive.
"${compose[@]}" restart app
"${compose[@]}" up -d --wait --wait-timeout 180
after_restart=$("${compose[@]}" exec -T postgres psql -U smoke -d smoke -Atc "$snapshot_sql")
test "$before_restart" = "$after_restart"
echo 'PASS: original uploaded bytes and template identity survive restart and reseed'
gc_counts=$("${compose[@]}" exec -T postgres psql -U smoke -d smoke -Atc "SELECT count(*) FROM cache WHERE key = 'complyflow-ai-cache-gc-smoke-expired'; SELECT count(*) FROM cache WHERE key IN ('complyflow-ai-cache-gc-smoke-active', 'unrelated-app-cache-old'); SELECT count(*) FROM cache_locks WHERE key = 'complyflow-ai-cache-gc-smoke-lock';")
test "$gc_counts" = $'0\n2\n1'
echo 'PASS: expired application cache collected after restart; active/foreign cache and lock preserved'
python3 tests/production/smoke.py
docker compose --profile e2e build e2e
docker run --rm --network complyflow-production-smoke_default \
  -v "$PWD:/workspace" -v /workspace/e2e/node_modules \
  -e E2E_UPSTREAM=http://app:10000 complyflow-e2e npx playwright test
"${compose[@]}" exec -T app sh -c 'test "$(id -u)" != 0 && test ! -f /app/apps/api/.env && test ! -d /app/apps/api/vendor/phpunit && test ! -d /app/apps/web && ! command -v gcc && ! command -v make && ! command -v node && ! command -v composer && ! python3.12 -c "import pytest" 2>/dev/null'
docker stats --no-stream --format '{{.Name}} {{.MemUsage}} {{.CPUPerc}}' complyflow-production-smoke-app-1
"${compose[@]}" exec -T app cat /sys/fs/cgroup/memory.peak
docker image inspect complyflow-ai:test --format 'Image bytes: {{.Size}}'
