<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Licensing\Listeners;

use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\License;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use Throwable;

final class LicenseLifecycleListener
{
    public function __construct(
        private readonly LicenseManagerInterface $licenseManager,
    ) {}

    public function onSubscriptionCreated(SubscriptionCreated $event): void
    {
        $subscription = Subscription::query()->find((int) $event->subscriptionId);

        if (! $subscription instanceof Subscription || $subscription->getAttribute('license_id') !== null) {
            return;
        }

        $planId = $subscription->getAttribute('plan_id');
        $ownerId = $subscription->getAttribute('subscribable_id');

        if (! is_numeric($planId) || ! is_numeric($ownerId)) {
            return;
        }

        try {
            $licenseKey = $this->licenseManager->generate((string) $planId, (string) $ownerId);
        } catch (Throwable) {
            return;
        }

        $license = License::query()->where('key', $licenseKey)->first();

        if (! $license instanceof License) {
            return;
        }

        $subscription->setAttribute('license_id', $license->getKey());
        $subscription->save();
    }

    public function onPaymentCompleted(PaymentCompleted $event): void
    {
        $this->updateLicenseStatus((int) $event->subscriptionId, 'active', true);
    }

    public function onSubscriptionRenewed(SubscriptionRenewed $event): void
    {
        $this->updateLicenseStatus((int) $event->subscriptionId, 'active', true);
    }

    public function onPaymentFailed(PaymentFailed $event): void
    {
        $this->updateLicenseStatus((int) $event->subscriptionId, 'past_due', false);
    }

    public function onSubscriptionRenewalFailed(SubscriptionRenewalFailed $event): void
    {
        $this->updateLicenseStatus((int) $event->subscriptionId, 'past_due', false);
    }

    public function onSubscriptionCancelled(SubscriptionCancelled $event): void
    {
        $this->updateLicenseStatus((int) $event->subscriptionId, 'cancelled', false);
    }

    private function updateLicenseStatus(int $subscriptionId, string $status, bool $refreshHeartbeat): void
    {
        if ($subscriptionId <= 0) {
            return;
        }

        $subscription = Subscription::query()->find($subscriptionId);

        if (! $subscription instanceof Subscription) {
            return;
        }

        $license = $subscription->license()->first();

        if ($license === null) {
            return;
        }

        $license->setAttribute('status', $status);

        if ($refreshHeartbeat) {
            $license->setAttribute('heartbeat_at', now());
        }

        $license->save();
    }
}
