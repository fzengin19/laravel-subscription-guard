<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\BillingProfileData;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\SyncBillingProfileJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\BillingProfile;

trait Billable
{
    public static function bootBillable(): void
    {
        static::saved(static function (self $model): void {
            if ($model->getKey() === null) {
                return;
            }

            SyncBillingProfileJob::dispatch($model::class, (string) $model->getKey());
        });
    }

    public function billingProfile(): MorphOne
    {
        return $this->morphOne(BillingProfile::class, 'billable');
    }

    public function getBillingProfile(): BillingProfileData
    {
        $profile = $this->billingProfile;

        return new BillingProfileData(
            name: $profile?->name ?? $this->name ?? null,
            email: $profile?->email ?? $this->email ?? null,
            companyName: $profile?->company_name,
            taxOffice: $profile?->tax_office,
            taxId: $profile?->tax_id,
            billingAddress: $profile?->billing_address,
            city: $profile?->city,
            country: $profile?->country,
            zip: $profile?->zip,
            phone: $profile?->phone,
            metadata: $profile?->metadata ?? [],
        );
    }

    public function hasBillingProfile(): bool
    {
        return $this->billingProfile()->exists();
    }
}
