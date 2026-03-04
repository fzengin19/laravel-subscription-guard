<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class PaymentResponse
{
    public function __construct(
        public bool $success,
        public ?string $transactionId = null,
        public ?string $redirectUrl = null,
        public ?string $iframeToken = null,
        public ?string $iframeUrl = null,
        public array $providerResponse = [],
        public ?string $failureReason = null,
    ) {}
}
