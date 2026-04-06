<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;

final class LicenseValidationController
{
    public function __construct(
        private readonly LicenseManagerInterface $licenseManager,
        private readonly LicenseRevocationStore $revocationStore,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $licenseKey = trim((string) $request->input('license_key', ''));

        if ($licenseKey === '') {
            return response()->json([
                'valid' => false,
                'reason' => 'License key is required.',
            ], 422);
        }

        if (strlen($licenseKey) > 2048) {
            return response()->json([
                'valid' => false,
                'reason' => 'License key exceeds maximum length.',
            ], 422);
        }

        $result = $this->licenseManager->validate($licenseKey);

        if (! $result->valid) {
            return response()->json([
                'valid' => false,
                'reason' => $result->reason,
                'metadata' => $result->metadata,
            ], 422);
        }

        $payload = is_array($result->metadata['payload'] ?? null) ? $result->metadata['payload'] : [];
        $licenseId = is_scalar($payload['license_id'] ?? null) ? (string) $payload['license_id'] : '';

        if ($licenseId !== '') {
            $this->revocationStore->touchHeartbeat($licenseId, time());
        }

        return response()->json([
            'valid' => true,
            'metadata' => $result->metadata,
        ]);
    }
}
