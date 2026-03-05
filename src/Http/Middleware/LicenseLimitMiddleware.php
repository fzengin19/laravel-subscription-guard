<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use Symfony\Component\HttpFoundation\Response;

final class LicenseLimitMiddleware
{
    public function __construct(private readonly FeatureGateInterface $featureGate) {}

    public function handle(
        Request $request,
        Closure $next,
        string $metric,
        string $amount = '1',
        string $licenseParam = 'license_key'
    ): Response {
        $licenseKey = $this->resolveLicenseKey($request, $licenseParam);
        $usageAmount = (float) $amount;

        if ($licenseKey === null || $usageAmount <= 0) {
            return response()->json(['message' => 'Invalid license limit request.'], 400);
        }

        $currentLimit = $this->featureGate->limit($licenseKey, $metric);
        $currentUsage = $this->featureGate->currentUsage($licenseKey, $metric);

        if ($currentLimit <= 0 || ($currentUsage + $usageAmount) > (float) $currentLimit) {
            return response()->json(['message' => 'License limit exceeded.'], 429);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 400 && ! $this->featureGate->incrementUsage($licenseKey, $metric, $usageAmount)) {
            return response()->json(['message' => 'License limit exceeded.'], 429);
        }

        return $response;
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
