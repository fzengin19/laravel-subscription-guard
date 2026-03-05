<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Data;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;

final class PaytrPaymentResponse
{
    public function __construct(
        public bool $success,
        public ?string $transactionId = null,
        public ?string $iframeToken = null,
        public ?string $iframeUrl = null,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}

    public function toPaymentResponse(): PaymentResponse
    {
        return new PaymentResponse(
            success: $this->success,
            transactionId: $this->transactionId,
            iframeToken: $this->iframeToken,
            iframeUrl: $this->iframeUrl,
            providerResponse: $this->raw,
            failureReason: $this->failureReason,
        );
    }
}
