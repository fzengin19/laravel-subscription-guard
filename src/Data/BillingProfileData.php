<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class BillingProfileData
{
    public function __construct(
        public ?string $name,
        public ?string $email,
        public ?string $companyName,
        public ?string $taxOffice,
        public ?string $taxId,
        public ?string $billingAddress,
        public ?string $city,
        public ?string $country,
        public ?string $zip,
        public ?string $phone,
        public array $metadata = [],
    ) {}
}
