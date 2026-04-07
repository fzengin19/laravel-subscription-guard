# Security

Use this document to understand the package's security posture, built-in protections, and operational security considerations.

## Security Model

The package handles payment data, webhook intake from external providers, license signing, and subscription state mutations. Security is applied at multiple layers:

1. **Input validation** — webhook payloads, callback data, license keys
2. **Signature verification** — webhook and callback integrity
3. **Data sanitization** — card data stripping from provider responses
4. **Concurrency protection** — cache locks and database locks
5. **Access control** — rate limiting, payload size limits, SSRF protection
6. **Cryptographic integrity** — Ed25519 license signing and verification

## Webhook Security

### Signature Validation

Webhook signatures are validated **before** the payload is persisted to the database. This prevents storing unverified data from untrusted sources.

- **Iyzico**: HMAC-SHA256 signature in `x-iyz-signature-v3` header
- **PayTR**: HMAC-SHA256 signature in `x-paytr-signature` header (base64-encoded)

Signature validation is bypassed only in mock mode. In production, mock mode triggers a `critical` log warning if enabled.

### Payload Size Limit

Webhook payloads are limited to a configurable maximum size (default: 64KB). Requests exceeding this limit are rejected before processing.

Config: `subscription-guard.webhooks.max_payload_size_kb`

### Rate Limiting

Webhook endpoints are rate-limited via Laravel's throttle middleware:

- Key: `webhook-intake`
- Default: 120 requests per minute
- Configurable via `subscription-guard.webhooks.rate_limit`

### Idempotency

Webhook deduplication uses `provider` + `event_id` to prevent replay attacks and accidental reprocessing. Duplicate webhooks are accepted (HTTP 200) but not re-finalized.

## Callback Security

Payment callbacks (3DS, checkout returns) follow similar patterns:

- Signature validation at intake
- Cache lock to prevent concurrent processing
- Idempotent transaction handling

Lock configuration: `locks.callback_lock_ttl` and `locks.callback_block_timeout`.

## Card Data Sanitization

The `SanitizesProviderData` trait strips sensitive payment data from all provider responses before storage. Removed fields include:

- `card_number`, `cardNumber`, `pan`
- `cvc`, `cvv`, `cvv2`, `cvc2`, `security_code`
- `expire_month`, `expireMonth`, `expire_year`, `expireYear`
- `card_holder_name`, `cardHolderName`
- `payment_card`, `paymentCard`

Exception messages are also sanitized to remove file paths and stack traces from stored data.

## License Security

### Signing

License keys are signed with Ed25519 using `ext-sodium`. The signing process:

1. Normalizes payload keys for canonical ordering
2. Signs with the configured private key
3. Produces a URL-safe base64 encoded key with detached signature

### Validation

License validation checks:

1. Key format integrity
2. Detached signature verification
3. Version and algorithm validation
4. Expiration timestamp
5. Revocation status
6. Heartbeat freshness
7. Clock skew tolerance

### Key Management

- Public and private keys are stored in environment variables (`SUBGUARD_LICENSE_PUBLIC_KEY`, `SUBGUARD_LICENSE_PRIVATE_KEY`)
- Keys must never appear in config files, documentation, or version control
- The `key_id` field in the license payload allows future key rotation

### Revocation

The revocation store supports full snapshots and sequential deltas. Out-of-order or replayed deltas are rejected. Configurable fail-open/fail-closed behavior on expired revocation state.

## SSRF Protection

The `subguard:sync-license-revocations` command validates endpoint URLs:

- URL format validation
- HTTPS required in production environments
- Private and reserved IP ranges are blocked via `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`
- Only HTTP/HTTPS schemes allowed

## Mock Mode Guard

Both Iyzico and PayTR providers log a `critical` warning if mock mode is active in production. This prevents accidental deployment with bypassed signature validation.

Check your logs for: `"webhook signature validation bypassed: mock mode is active in production"`

## Concurrency Safety

All state-mutating operations use layered concurrency protection:

| Layer | Mechanism | Purpose |
|---|---|---|
| Cache lock | `cache()->lock()` with TTL | Prevent duplicate job execution |
| Database lock | `lockForUpdate()` in transaction | Prevent race conditions on row mutations |

See [Queues And Jobs](QUEUES-AND-JOBS.md) for the full lock inventory.

## Mass Assignment Protection

Sensitive model fields are guarded:

- `Invoice.status` is in `$guarded`
- Transaction state transitions use explicit methods (`markProcessed`, `markFailed`, `markRetrying`), not mass assignment

## Configuration Security

| Setting | Purpose | Production Guidance |
|---|---|---|
| `providers.drivers.*.mock` | Bypass provider calls | Must be `false` in production |
| `license.keys.private` | License signing key | Env-only, never in source |
| `license.keys.public` | License verification key | Env-only, never in source |
| `providers.drivers.*.secret_key` | Provider API secret | Env-only |
| `providers.drivers.paytr.merchant_key` | PayTR signing key | Env-only |
| `providers.drivers.paytr.merchant_salt` | PayTR signing salt | Env-only |

## Security Audit History

A security audit was performed on 2026-04-07, identifying and fixing 32 findings across these categories:

- Webhook signature validation timing (validate before persist)
- Mock mode production guard
- Subscription race condition protection
- Card data sanitization
- Billing period calculation safety
- Payload size limiting
- SSRF protection on sync endpoints
- Secure random token generation
- Metadata filter safety

All 32 findings were fixed and verified with 230 passing tests.

## Reporting Security Issues

If you discover a security vulnerability, please report it responsibly. Do not open a public issue. Contact the maintainers directly.

## Related Documents

- [Webhooks](WEBHOOKS.md)
- [Callbacks](CALLBACKS.md)
- [Domain Licensing](DOMAIN-LICENSING.md)
- [Queues And Jobs](QUEUES-AND-JOBS.md)
- [Configuration](CONFIGURATION.md)
