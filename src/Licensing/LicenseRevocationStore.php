<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Licensing;

use Illuminate\Support\Facades\Log;
use Throwable;

final class LicenseRevocationStore
{
    public function applyFullSnapshot(int $sequence, array $revokedLicenseIds, int $ttlSeconds): bool
    {
        if ($sequence < 0 || $ttlSeconds <= 0) {
            return false;
        }

        return $this->withLock(function () use ($sequence, $revokedLicenseIds, $ttlSeconds): bool {
            $state = $this->state();

            if ($sequence <= (int) $state['sequence']) {
                return false;
            }

            $this->persistState([
                'sequence' => $sequence,
                'revoked' => $this->normalizeIdsAsMap($revokedLicenseIds),
                'expires_at' => time() + $ttlSeconds,
            ], $ttlSeconds);

            return true;
        });
    }

    public function applyDelta(int $sequence, array $revokeIds, array $restoreIds, int $ttlSeconds): bool
    {
        if ($sequence < 0 || $ttlSeconds <= 0) {
            return false;
        }

        return $this->withLock(function () use ($sequence, $revokeIds, $restoreIds, $ttlSeconds): bool {
            $state = $this->state();
            $currentSequence = (int) $state['sequence'];

            if ($sequence <= $currentSequence) {
                return false;
            }

            if ($sequence > ($currentSequence + 1)) {
                return false;
            }

            $revoked = (array) $state['revoked'];

            foreach ($this->normalizeIds($revokeIds) as $licenseId) {
                $revoked[$licenseId] = true;
            }

            foreach ($this->normalizeIds($restoreIds) as $licenseId) {
                unset($revoked[$licenseId]);
            }

            $this->persistState([
                'sequence' => $sequence,
                'revoked' => $revoked,
                'expires_at' => time() + $ttlSeconds,
            ], $ttlSeconds);

            return true;
        });
    }

    public function isRevoked(string $licenseId): bool
    {
        if ($licenseId === '') {
            return false;
        }

        try {
            $state = $this->state();
        } catch (Throwable $e) {
            Log::channel(
                (string) config('subscription-guard.logging.licenses_channel', 'subguard_licenses')
            )->error('License revocation cache unreachable', [
                'license_id' => $licenseId,
                'error' => $e->getMessage(),
            ]);

            return ! (bool) config('subscription-guard.license.revocation.fail_open_on_expired', false);
        }

        $expiresAt = (int) $state['expires_at'];
        $sequence = (int) $state['sequence'];

        // Store hiç populate edilmemişse revoke edilmiş lisans olamaz
        if ($sequence === 0 && $expiresAt === 0) {
            return false;
        }

        // Store populate edilmiş ama expire olmuşsa config'e göre karar ver
        if ($expiresAt < time()) {
            return ! (bool) config('subscription-guard.license.revocation.fail_open_on_expired', false);
        }

        return (bool) (($state['revoked'][$licenseId] ?? false) === true);
    }

    public function currentSequence(): int
    {
        return (int) $this->state()['sequence'];
    }

    public function touchHeartbeat(string $licenseId, ?int $timestamp = null): void
    {
        if ($licenseId === '') {
            return;
        }

        cache()->put($this->heartbeatKey($licenseId), $timestamp ?? time(), $this->heartbeatTtlSeconds());
    }

    public function heartbeatAt(string $licenseId): ?int
    {
        if ($licenseId === '') {
            return null;
        }

        $value = cache()->get($this->heartbeatKey($licenseId));

        return is_int($value) ? $value : null;
    }

    private function withLock(callable $callback): bool
    {
        $lock = cache()->lock($this->cacheKey().':lock', 10);

        try {
            return (bool) $lock->block(5, $callback);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    private function state(): array
    {
        $value = cache()->get($this->cacheKey());

        if (! is_array($value)) {
            return [
                'sequence' => 0,
                'revoked' => [],
                'expires_at' => 0,
            ];
        }

        return [
            'sequence' => (int) ($value['sequence'] ?? 0),
            'revoked' => is_array($value['revoked'] ?? null) ? $value['revoked'] : [],
            'expires_at' => (int) ($value['expires_at'] ?? 0),
        ];
    }

    private function persistState(array $state, int $ttlSeconds): void
    {
        cache()->put($this->cacheKey(), $state, $ttlSeconds);
    }

    private function normalizeIds(array $licenseIds): array
    {
        $normalized = [];

        foreach ($licenseIds as $licenseId) {
            if (! is_scalar($licenseId)) {
                continue;
            }

            $value = trim((string) $licenseId);

            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    private function normalizeIdsAsMap(array $licenseIds): array
    {
        $values = $this->normalizeIds($licenseIds);
        $map = [];

        foreach ($values as $value) {
            $map[$value] = true;
        }

        return $map;
    }

    private function cacheKey(): string
    {
        return (string) config('subscription-guard.license.revocation.cache_key', 'subguard:license:revocation');
    }

    private function heartbeatKey(string $licenseId): string
    {
        $prefix = (string) config('subscription-guard.license.offline.heartbeat_cache_prefix', 'subguard:license:heartbeat:');

        return $prefix.$licenseId;
    }

    private function heartbeatTtlSeconds(): int
    {
        return max(60, (int) config('subscription-guard.license.offline.heartbeat_ttl_seconds', 1_209_600));
    }
}
