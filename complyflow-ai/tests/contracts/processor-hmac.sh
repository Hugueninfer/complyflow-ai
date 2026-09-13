#!/usr/bin/env bash
set -euo pipefail

# Run from repository root. No media or credentials are retained on disk.
docker compose run --rm -T api php tests/Support/export-signed-processor-request.php |
    docker compose run --rm -T processor env PYTHONPATH=. python tests/verify_php_contract.py
