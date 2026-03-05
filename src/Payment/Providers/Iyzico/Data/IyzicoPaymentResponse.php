<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Data;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;

final class IyzicoPaymentResponse
{
    public function __construct(
        public bool $success,
        public ?string $paymentId = null,
        public ?string $conversationId = null,
        public ?string $checkoutFormToken = null,
        public ?string $redirectUrl = null,
        public array $raw = [],
        public ?string $failureReason = null,
    ) {}

    public function toPaymentResponse(): PaymentResponse
    {
        return new PaymentResponse(
            success: $this->success,
            transactionId: $this->paymentId,
            redirectUrl: $this->redirectUrl,
            iframeToken: $this->checkoutFormToken,
            iframeUrl: $this->redirectUrl,
            providerResponse: $this->raw,
            failureReason: $this->failureReason,
        );
    }
}
