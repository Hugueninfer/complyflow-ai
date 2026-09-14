# Internal processor contract

`processor.yaml` is the canonical OpenAPI 3.1 document, serialized as JSON
(a YAML 1.2 subset) to avoid adding a YAML dependency to the Python runtime.
The contract test compares it with the live `/openapi.json` document.
Regenerate from `app.main.app.openapi()` whenever the contract changes.

Only the Laravel server calls `POST /v1/analyze`. Configure the same
`PROCESSOR_HMAC_SECRET` in both server processes through the environment;
there is no built-in secret. Never send this secret to a browser. Do not
expose the processor through a public reverse proxy; production binds it
to loopback. CORS is not enabled. `/health` remains unauthenticated.

Sign the **exact UTF-8 bytes sent on the wire**, including spaces, JSON key
order, Unicode escapes, and any final newline. Hash with SHA-256 and encode
the digest as lowercase hex. Join these three strings with a single LF:

1. `X-CF-Timestamp`: Unix seconds, decimal digits (1–12).
2. `X-CF-Nonce`: a fresh value for every attempt (1–128 ASCII letters,
   digits, `_` or `-`; a UUID is appropriate).
3. The lowercase hex body digest.

There is **no final LF** on that joined message. HMAC-SHA256 it using the
UTF-8 secret, encode as lowercase hex, and send as `X-CF-Signature`.
Do not sign a decoded/Pydantic-reserialized request. Headers must appear
exactly once. Client clock skew must be within 60 seconds, inclusive;
the server's clock retains subsecond precision for this comparison.

Nonces are claimed atomically after signature verification and before
JSON/schema validation. Every retry needs a new nonce, including retries
after 422 or pipeline errors; reuse the request's business idempotency key.
The process-local cache retains each accepted nonce for 120 seconds using
monotonic time, including the exact 120-second endpoint, cleans entries
strictly after that endpoint on access, and holds at most 10,000
entries. At capacity it returns 503 without evicting live entries. Run a
single processor worker; multiple workers/replicas require a shared atomic
nonce store. Restarting the process clears this cache.

The canonical fixture `hmac-test-vector.json` contains exact body bytes
(`body_base64`, also readable as `body_utf8`), timestamp, nonce, a clearly
test-only secret, SHA-256 digest, signed message, and expected signature.
It was generated independently with Node `crypto`; PHP and Python tests verify it.
`tests/contracts/processor-hmac.sh` decodes the body bytes, signs them, and compares
the literal signature with the clock fixed at the fixture timestamp.
The fixture PDF data is only a header, suitable for contract validation;
it is not a complete document for the future extraction pipeline.

All input/output objects forbid extra fields. UUIDs are public IDs; they
do not confer authorization. `requires_human_review` is always explicitly
`true`. `missing` findings require empty citations and a nonblank
`search_summary`; other statuses require citations and may set
`search_summary` to `null`. Citation offsets are page-relative character
positions with an exclusive end greater than the start. Chunk offsets are
page-relative with end >= start; `index` is zero-based and embeddings
contain exactly 384 finite numbers. Pages use one-based `page_number`.
The pipeline additionally validates document hashes/base64, real
page membership, evidence text/offset correspondence, and request/response
IDs before returning accepted artifacts. Laravel repeats
boundary/evidence validation before persisting results.

Responses have these stable shapes:

- 200: `AnalyzeResponse` with validated findings, pages and chunks.
- 401: `{"detail":"invalid_authentication"}` for missing, malformed,
  invalid, expired, or replayed authentication.
- 422: `{"detail":"invalid_request"}` for invalid JSON/schema, with no
  input/document echo.
- 503: `{"detail":"authentication_unavailable"}` for missing secret or
  full nonce cache; provider/configuration failures use sanitized public codes
  such as `provider_not_configured`, `provider_unavailable` or `analysis_failed`.

Every attempt shares one absolute monotonic deadline, including all documents,
retrieval, requirement calls and response serialization. Configure
`PROCESSOR_ANALYSIS_TIMEOUT_SECONDS` identically in Laravel and Python:
default 45 seconds, finite and strictly positive, with a maximum of 45.
PDF extraction is capped at `min(30, remaining)` seconds and each provider
HTTP operation at `min(20, remaining)` seconds. Laravel's
`PROCESSOR_HTTP_TIMEOUT_SECONDS` defaults to 60, must be at least 15 seconds
above the shared budget and at most 60 (before the 75-second queue job timeout).
The cross-language contract check verifies these effective relationships.

503 `analysis_budget_exceeded`, `analysis_cancelled`, `analysis_in_progress`
and `analysis_capacity_exceeded` are retryable. `analysis_not_configured`
signals invalid time configuration and is terminal in Laravel. Disconnect
and ASGI task cancellation propagate a cooperative cancellation token to CPU
loops, the PDF pipe/child cleanup and cancellable HTTP I/O. No partial result
is returned for persistence. At most **one analysis** runs in this process,
including cleanup: duplicate analysis IDs or idempotency-key hashes are
rejected without starting another pipeline. Other work is rejected at capacity.
The active entry is released by the worker in `finally`, never by expiration
or merely because its HTTP waiter ended. This bounded registry stores no
results or completed-key history. It resets on restart and cannot coordinate
multiple processes; Laravel owns durable idempotency and terminal states.

Neither request bodies nor secrets are logged by these handlers.
