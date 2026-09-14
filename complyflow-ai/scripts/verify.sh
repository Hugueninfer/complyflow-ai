#!/usr/bin/env bash
# Destructive only to the configured local Compose database. Use a disposable project.
set -euo pipefail
export PROCESSOR_HMAC_SECRET="${PROCESSOR_HMAC_SECRET:-$(openssl rand -hex 32)}"
docker compose up -d postgres processor api web --wait --wait-timeout 120
docker compose run --rm api php artisan test
docker compose run --rm --no-deps processor pytest -q
docker compose run --rm --no-deps web npm run test -- --run
docker compose run --rm --no-deps web npm run typecheck
docker compose run --rm --no-deps web npm run lint
docker compose run --rm --no-deps web npm run build
bash tests/contracts/processor-hmac.sh
# Domain tests use this database; restore deterministic browser/demo input afterward.
docker compose run --rm api php artisan migrate:fresh --seed --force
docker compose run --rm api php artisan migrate --force
docker compose run --rm api php artisan db:seed --force
docker compose --profile e2e run --rm e2e npx playwright test
curl --fail --silent --show-error http://127.0.0.1:8000/api/health
