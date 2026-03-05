<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use Symfony\Component\HttpFoundation\Response;

final class LicenseFeatureMiddleware
{
    public function __construct(private readonly FeatureGateInterface $featureGate) {}

    public function handle(Request $request, Closure $next, string $feature, string $licenseParam = 'license_key'): Response
    {
        $licenseKey = $this->resolveLicenseKey($request, $licenseParam);

        if ($licenseKey === null || ! $this->featureGate->can($licenseKey, $feature)) {
            return response()->json(['message' => 'License feature is not available.'], 403);
        }

        return $next($request);
    }

    private function resolveLicenseKey(Request $request, string $licenseParam): ?string
    {
        $value = $request->route($licenseParam)
            ?? $request->input($licenseParam)
            ?? $request->header('X-SubGuard-License-Key');

        if (! is_scalar($value)) {
            return null;
        }

        $licenseKey = trim((string) $value);

        return $licenseKey === '' ? null : $licenseKey;
    }
}
