<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Licensing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\ValidationResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\LicenseActivation;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;

final class LicenseManager implements LicenseManagerInterface
{
    public function __construct(
        private readonly LicenseSignature $signature,
        private readonly LicenseRevocationStore $revocationStore,
    ) {}

    public function algorithm(): string
    {
        return (string) config('subscription-guard.license.algorithm', 'ed25519');
    }

    public function generate(int|string $planId, int|string $ownerId): string
    {
        $issuedAt = time();
        $ttl = (int) config('subscription-guard.license.default_ttl_seconds', 60 * 60 * 24 * 30);

        $licenseId = (string) Str::uuid();

        $licenseKey = $this->signature->sign([
            'v' => 1,
            'alg' => $this->algorithm(),
            'kid' => (string) config('subscription-guard.license.key_id', 'v1'),
            'license_id' => $licenseId,
            'plan_id' => $planId,
            'owner_id' => $ownerId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            'hb' => max(1, (int) config('subscription-guard.license.offline.max_stale_seconds', 604800)),
        ]);

        $this->revocationStore->touchHeartbeat($licenseId, $issuedAt);
        $this->persistLicenseRecord($licenseKey, [
            'plan_id' => $planId,
            'owner_id' => $ownerId,
            'exp' => $issuedAt + $ttl,
        ]);

        return $licenseKey;
    }

    public function validate(string $licenseKey): ValidationResult
    {
        $result = $this->signature->verify($licenseKey);

        if (! $result->valid) {
            return $result;
        }

        $payload = is_array($result->metadata['payload'] ?? null) ? $result->metadata['payload'] : [];
        $expiresAt = isset($payload['exp']) ? (int) $payload['exp'] : 0;

        if ($expiresAt > 0 && time() > $expiresAt) {
            return new ValidationResult(false, 'License key expired.', ['payload' => $payload]);
        }

        $licenseId = is_scalar($payload['license_id'] ?? null) ? (string) $payload['license_id'] : '';

        if ($licenseId === '') {
            return new ValidationResult(false, 'License id is missing.', ['payload' => $payload]);
        }

        if ($this->revocationStore->isRevoked($licenseId)) {
            return new ValidationResult(false, 'License revoked.', ['payload' => $payload]);
        }

        $maxStaleSeconds = (int) ($payload['hb'] ?? config('subscription-guard.license.offline.max_stale_seconds', 604800));
        $maxStaleSeconds = max(1, $maxStaleSeconds);
        $clockSkewSeconds = max(0, (int) config('subscription-guard.license.offline.clock_skew_seconds', 60));
        $heartbeatAt = $this->revocationStore->heartbeatAt($licenseId);

        if ($heartbeatAt === null || (time() - $heartbeatAt) > ($maxStaleSeconds + $clockSkewSeconds)) {
            return new ValidationResult(false, 'License heartbeat is stale.', ['payload' => $payload]);
        }

        return new ValidationResult(true, metadata: ['payload' => $payload]);
    }

    public function activate(string $licenseKey, string $domain): bool
    {
        if ($licenseKey === '' || $domain === '') {
            return false;
        }

        $validation = $this->validate($licenseKey);

        if (! $validation->valid) {
            return false;
        }

        return DB::transaction(function () use ($licenseKey, $domain): bool {
            $license = License::query()
                ->where('key', $licenseKey)
                ->lockForUpdate()
                ->first();

            if (! $license instanceof License) {
                return false;
            }

            if (! in_array((string) $license->getAttribute('status'), ['active', 'trialing'], true)) {
                return false;
            }

            $boundDomain = (string) ($license->getAttribute('domain') ?? '');

            if ($boundDomain !== '' && $boundDomain !== $domain) {
                return false;
            }

            $existingActivation = LicenseActivation::query()
                ->where('license_id', $license->getKey())
                ->where('domain', $domain)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->first();

            if ($existingActivation instanceof LicenseActivation) {
                return true;
            }

            $maxActivations = max(1, (int) $license->getAttribute('max_activations'));
            $activeCount = LicenseActivation::query()
                ->where('license_id', $license->getKey())
                ->whereNull('deactivated_at')
                ->count();

            if ($activeCount >= $maxActivations) {
                return false;
            }

            LicenseActivation::query()->create([
                'license_id' => $license->getKey(),
                'domain' => $domain,
                'activated_at' => now(),
                'metadata' => [],
            ]);

            $license->setAttribute('current_activations', $activeCount + 1);

            if ($boundDomain === '') {
                $license->setAttribute('domain', $domain);
            }

            $license->save();

            return true;
        });
    }

    public function deactivate(string $licenseKey, string $domain): bool
    {
        if ($licenseKey === '' || $domain === '') {
            return false;
        }

        return DB::transaction(function () use ($licenseKey, $domain): bool {
            $license = License::query()
                ->where('key', $licenseKey)
                ->lockForUpdate()
                ->first();

            if (! $license instanceof License) {
                return false;
            }

            $activation = LicenseActivation::query()
                ->where('license_id', $license->getKey())
                ->where('domain', $domain)
                ->whereNull('deactivated_at')
                ->lockForUpdate()
                ->first();

            if (! $activation instanceof LicenseActivation) {
                return false;
            }

            $activation->setAttribute('deactivated_at', now());
            $activation->save();

            $activeCount = LicenseActivation::query()
                ->where('license_id', $license->getKey())
                ->whereNull('deactivated_at')
                ->count();

            $license->setAttribute('current_activations', $activeCount);
            $license->save();

            return true;
        });
    }

    public function checkFeature(string $licenseKey, string $feature): bool
    {
        if ($feature === '') {
            return false;
        }

        $validation = $this->validate($licenseKey);

        if (! $validation->valid) {
            return false;
        }

        $payload = is_array($validation->metadata['payload'] ?? null) ? $validation->metadata['payload'] : [];
        $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];

        if ($features === []) {
            return false;
        }

        return in_array($feature, $features, true);
    }

    public function checkLimit(string $licenseKey, string $limit): int
    {
        if ($limit === '') {
            return 0;
        }

        $validation = $this->validate($licenseKey);

        if (! $validation->valid) {
            return 0;
        }

        $payload = is_array($validation->metadata['payload'] ?? null) ? $validation->metadata['payload'] : [];
        $limits = is_array($payload['limits'] ?? null) ? $payload['limits'] : [];

        if (! array_key_exists($limit, $limits)) {
            return 0;
        }

        return (int) $limits[$limit];
    }

    public function revoke(string $licenseKey, string $reason): bool
    {
        if ($licenseKey === '' || $reason === '') {
            return false;
        }

        $validation = $this->validate($licenseKey);

        if (! $validation->valid) {
            return false;
        }

        $payload = is_array($validation->metadata['payload'] ?? null) ? $validation->metadata['payload'] : [];
        $licenseId = is_scalar($payload['license_id'] ?? null) ? (string) $payload['license_id'] : '';

        if ($licenseId === '') {
            return false;
        }

        $ttl = max(1, (int) config('subscription-guard.license.revocation.snapshot_ttl_seconds', 604800));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $nextSequence = $this->revocationStore->currentSequence() + 1;

            if ($this->revocationStore->applyDelta($nextSequence, [$licenseId], [], $ttl)) {
                return true;
            }
        }

        return false;
    }

    private function persistLicenseRecord(string $licenseKey, array $payload): void
    {
        $ownerId = is_scalar($payload['owner_id'] ?? null) ? (int) $payload['owner_id'] : 0;
        $planId = is_scalar($payload['plan_id'] ?? null) ? (int) $payload['plan_id'] : 0;

        if ($ownerId <= 0 || $planId <= 0) {
            return;
        }

        $userModelClass = (string) config('auth.providers.users.model');

        if ($userModelClass === '' || ! class_exists($userModelClass)) {
            return;
        }

        $owner = $userModelClass::query()->find($ownerId);
        $plan = Plan::query()->find($planId);

        if (! $owner instanceof Model || ! $plan instanceof Plan) {
            return;
        }

        License::unguarded(static fn (): License => License::query()->firstOrCreate(
            ['key' => $licenseKey],
            [
                'user_id' => $ownerId,
                'plan_id' => $planId,
                'status' => 'active',
                'expires_at' => isset($payload['exp']) ? now()->setTimestamp((int) $payload['exp']) : null,
                'heartbeat_at' => now(),
            ]
        ));
    }
}
