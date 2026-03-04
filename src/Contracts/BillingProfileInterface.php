<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Contracts;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\BillingProfileData;

interface BillingProfileInterface
{
    public function getBillingProfile(): BillingProfileData;

    public function hasBillingProfile(): bool;
}
