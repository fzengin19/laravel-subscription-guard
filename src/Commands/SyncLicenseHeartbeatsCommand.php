<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use DateTimeInterface;
use Illuminate\Console\Command;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseSignature;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;

final class SyncLicenseHeartbeatsCommand extends Command
{
    protected $signature = 'subguard:sync-license-heartbeats {--batch=500 : Max license rows to scan} {--statuses=active,trialing : Comma-separated statuses to include}';

    protected $description = 'Sync heartbeat cache values from persisted licenses';

    public function handle(LicenseSignature $signature, LicenseRevocationStore $store): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $statuses = $this->resolveStatuses((string) $this->option('statuses'));

        if ($statuses === []) {
            $this->error('At least one status must be provided in --statuses option.');

            return self::FAILURE;
        }

        $licenses = License::query()
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $synced = 0;

        foreach ($licenses as $license) {
            $licenseKey = trim((string) $license->getAttribute('key'));

            if ($licenseKey === '') {
                continue;
            }

            $validated = $signature->verify($licenseKey);

            if (! $validated->valid) {
                continue;
            }

            $payload = is_array($validated->metadata['payload'] ?? null) ? $validated->metadata['payload'] : [];
            $licenseId = is_scalar($payload['license_id'] ?? null) ? trim((string) $payload['license_id']) : '';

            if ($licenseId === '') {
                continue;
            }

            $heartbeatAt = $license->getAttribute('heartbeat_at');
            $timestamp = $heartbeatAt instanceof DateTimeInterface ? $heartbeatAt->getTimestamp() : time();
            $store->touchHeartbeat($licenseId, $timestamp);
            $synced++;
        }

        $this->info(sprintf('Heartbeat sync completed. scanned=%d synced=%d', $licenses->count(), $synced));

        return self::SUCCESS;
    }

    private function resolveStatuses(string $raw): array
    {
        $parts = array_filter(array_map(static fn (string $part): string => trim($part), explode(',', $raw)));

        if ($parts === []) {
            return [];
        }

        $normalized = [];

        foreach ($parts as $part) {
            $normalized[$part] = true;
        }

        return array_keys($normalized);
    }
}
