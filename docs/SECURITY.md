# Security

Use this document to understand the package's security posture, built-in protections, and operational security considerations.

## Production Hardening (v1.1.0 + v1.2.0 + v1.3.0)

### Cascade-Delete Defense (v1.3.0)

The package defines `RESTRICT` foreign keys on parent references that, if violated, would silently destroy billing audit data:

- `subscriptions.plan_id → plans.id`
- `licenses.plan_id → plans.id`
- `licenses.user_id → users.id`
- `subscription_items.plan_id → plans.id`

A `DELETE FROM plans WHERE id = ?` or `User::delete()` will now throw `Illuminate\Database\QueryException` if any dependent row exists, instead of cascading and physically wiping subscriptions/licenses. To archive a plan, soft-delete it: `$plan->delete()`. `$plan->forceDelete()` only succeeds after every dependent row is migrated away. The `Plan` model uses `SoftDeletes`, and `Subscription::plan()` / `License::plan()` / `SubscriptionItem::plan()` chain `->withTrashed()` so historical billing keeps resolving archived plans.

Before v1.3.0 these foreign keys were declared `ON DELETE CASCADE`. Model-level `softDeletes` on `subscriptions` and `licenses` did NOT protect — InnoDB / SQLite FK enforcement runs at the database layer, below Eloquent.

### Mock-Mode Fail-Closed

`ProviderMockModeGuard::ensureNotProduction()` is invoked by `IyzicoSupport::mockMode()` and `PaytrProvider::mockMode()`. When `app()->environment('production')` and the corresponding `*_MOCK` env flag is true, the guard throws `ProviderException`. There is no critical-log-only bypass path remaining.

### Terminal-State Guard

`SubscriptionService::applySubscriptionStatus()` is the single entry point for every status write in the billing pipeline. It refuses any non-`Cancelled` target on a subscription whose current status is `Cancelled` and logs the rejected attempt to the `subguard_payments` channel. Wraps:

- `recordWebhookTransaction` (late webhook events)
- `handlePaymentResult` (3DS / checkout callbacks)
- `PaymentChargeJob::handle` (in-flight charge job)
- `ProcessDunningRetryJob::handleDunningExhaustion`

### Idempotency-Hash Safety

`Support\Json::safeHash()` (v1.2.0) is used at every webhook `eventId` fallback site (`IyzicoProvider::eventId`, `PaytrProvider::processWebhook`, `IyzicoProviderEventDispatcher::dispatch`). It uses `JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE`, falls back to `serialize()` on `JsonException`, and never returns `hash('sha256', '')` for non-UTF8 or unencodable input. Replaces the prior `(string) json_encode($x)` pattern that collapsed to the empty-string hash for binary or invalid-UTF8 payloads.

### Sensitive Header Filtering

Both `WebhookController::filterHeaders()` and (v1.2.0) `PaymentCallbackController::filterHeaders()` strip `authorization`, `cookie`, `set-cookie`, and `proxy-authorization` from the headers persisted to `webhook_calls.headers`. Webhook bodies are still stored in full for audit; only sensitive transport headers are removed.

### Cancel Race Defense

`SubscriptionService::cancel()` re-reads the subscription on both branches of the cache-lock outcome:

- On `Cache::lock(...)->get()` returning `false` (contention), the loser refreshes its model — if the winner's commit landed, the loser returns `true` idempotently instead of `false`.
- After acquiring the lock, the holder refreshes again before the remote provider call to avoid sending a cancel for an already-cancelled remote subscription.

## Security Model

The package handles payment data, webhook intake from external providers, license signing, and subscription state mutations. Security is applied at multiple layers:

1. **Input validation** — webhook payloads, callback data, license keys
2. **Signature verification** — webhook and callback integrity
3. **Data sanitization** — card data stripping from provider responses
4. **Concurrency protection** — cache locks and database locks
5. **Access control** — rate limiting, payload size limits, SSRF protection
6. **Cryptographic integrity** — Ed25519 license signing and verification
7. **Production fail-closed** — mock-mode guard, terminal-state guard, idempotency-hash safety (v1.1.0–v1.2.0)

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
