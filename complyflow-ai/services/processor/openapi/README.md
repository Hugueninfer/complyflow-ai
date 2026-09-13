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
It was generated independently with Node `crypto`; Python tests verify it.
Task 9 should decode the body bytes, sign them, and compare the literal
signature. Freeze the clock at the fixture timestamp for an HTTP test.
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
The pipeline must additionally validate document hashes/base64, real
page membership, evidence text/offset correspondence, and request/response
IDs before returning accepted artifacts (Tasks 7–8). Laravel repeats
boundary/evidence validation before persisting results (Task 9).

Responses have these stable shapes:

- 200: `AnalyzeResponse`, reserved for the pipeline added in Task 8.
- 401: `{"detail":"invalid_authentication"}` for missing, malformed,
  invalid, expired, or replayed authentication.
- 422: `{"detail":"invalid_request"}` for invalid JSON/schema, with no
  input/document echo.
- 503: `{"detail":"authentication_unavailable"}` for missing secret or
  full nonce cache; `{"detail":"analysis_not_available"}` for valid
  requests until Task 8 installs the pipeline.

Neither request bodies nor secrets are logged by these handlers.
