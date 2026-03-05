<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;

final class SyncLicenseRevocationsCommand extends Command
{
    protected $signature = 'subguard:sync-license-revocations {--endpoint= : Revocation sync endpoint URL} {--token= : Optional bearer token} {--timeout= : Request timeout in seconds}';

    protected $description = 'Sync license revocation snapshot/delta from remote endpoint';

    public function handle(LicenseRevocationStore $store): int
    {
        $endpoint = $this->resolveEndpoint();

        if ($endpoint === '') {
            $this->error('Revocation endpoint is missing. Configure subscription-guard.license.revocation.sync_endpoint or pass --endpoint.');

            return self::FAILURE;
        }

        $timeout = $this->resolveTimeout();
        $token = $this->resolveToken();

        $request = Http::acceptJson()->timeout($timeout);

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->get($endpoint);

        if (! $response->successful()) {
            $this->error(sprintf('Revocation sync failed with status %d.', $response->status()));

            return self::FAILURE;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->error('Revocation response must be a JSON object.');

            return self::FAILURE;
        }

        $sequence = max(0, (int) ($payload['sequence'] ?? 0));
        $ttlSeconds = max(1, (int) ($payload['ttl_seconds'] ?? config('subscription-guard.license.revocation.snapshot_ttl_seconds', 604800)));
        $mode = strtolower(trim((string) ($payload['mode'] ?? 'full')));

        $applied = false;

        if ($mode === 'delta') {
            $revoked = $this->normalizeIds($payload['revoked'] ?? $payload['revoke'] ?? []);
            $restored = $this->normalizeIds($payload['restored'] ?? $payload['restore'] ?? []);

            $applied = $store->applyDelta($sequence, $revoked, $restored, $ttlSeconds);

            if (! $applied) {
                $fallbackRevoked = $this->normalizeIds($payload['snapshot_revoked'] ?? []);

                if ($fallbackRevoked !== []) {
                    $applied = $store->applyFullSnapshot($sequence, $fallbackRevoked, $ttlSeconds);
                }
            }
        } else {
            $revoked = $this->normalizeIds($payload['revoked'] ?? $payload['revoked_license_ids'] ?? []);
            $applied = $store->applyFullSnapshot($sequence, $revoked, $ttlSeconds);
        }

        $this->info(sprintf(
            'Revocation sync %s. mode=%s sequence=%d current_sequence=%d',
            $applied ? 'applied' : 'skipped',
            $mode,
            $sequence,
            $store->currentSequence()
        ));

        return self::SUCCESS;
    }

    private function resolveEndpoint(): string
    {
        $endpoint = trim((string) $this->option('endpoint'));

        if ($endpoint !== '') {
            return $endpoint;
        }

        return trim((string) config('subscription-guard.license.revocation.sync_endpoint', ''));
    }

    private function resolveToken(): string
    {
        $token = trim((string) $this->option('token'));

        if ($token !== '') {
            return $token;
        }

        return trim((string) config('subscription-guard.license.revocation.sync_token', ''));
    }

    private function resolveTimeout(): int
    {
        $optionTimeout = (int) $this->option('timeout');

        if ($optionTimeout > 0) {
            return $optionTimeout;
        }

        return max(1, (int) config('subscription-guard.license.revocation.sync_timeout_seconds', 10));
    }

    private function normalizeIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $licenseId = trim((string) $item);

            if ($licenseId === '') {
                continue;
            }

            $normalized[$licenseId] = true;
        }

        return array_keys($normalized);
    }
}
