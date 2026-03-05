<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Features;

use Illuminate\Support\Carbon;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;

final class ScheduleGate
{
    public function availableUntil(mixed $subject, string $feature): ?Carbon
    {
        $licenseKey = $this->resolveLicenseKey($subject);

        if ($licenseKey === null || $feature === '') {
            return null;
        }

        $license = License::query()->where('key', $licenseKey)->first();

        if (! $license instanceof License) {
            return null;
        }

        $overrides = $license->getAttribute('feature_overrides');

        if (is_array($overrides) && array_key_exists($feature, $overrides)) {
            $until = $this->extractUntil($overrides[$feature]);

            if ($until instanceof Carbon) {
                return $until;
            }
        }

        $metadata = $license->getAttribute('metadata');

        if (! is_array($metadata)) {
            return null;
        }

        $featureSchedule = $metadata['feature_schedule'] ?? null;

        if (! is_array($featureSchedule) || ! array_key_exists($feature, $featureSchedule)) {
            return null;
        }

        return $this->extractUntil($featureSchedule[$feature]);
    }

    private function extractUntil(mixed $value): ?Carbon
    {
        if (is_array($value)) {
            $until = $value['until'] ?? null;

            if (is_string($until) && $until !== '') {
                return Carbon::parse($until);
            }

            return null;
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function resolveLicenseKey(mixed $subject): ?string
    {
        if (is_string($subject)) {
            $value = trim($subject);

            return $value === '' ? null : $value;
        }

        if ($subject instanceof License) {
            $value = trim((string) $subject->getAttribute('key'));

            return $value === '' ? null : $value;
        }

        return null;
    }
}
