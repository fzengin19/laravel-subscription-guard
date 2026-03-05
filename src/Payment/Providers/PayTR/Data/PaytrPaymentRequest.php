<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\Data;

use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentRequest;

final class PaytrPaymentRequest extends PaymentRequest
{
    public static function fromArray(array $payload): self
    {
        $base = PaymentRequest::fromArray($payload);

        return new self($base->amount, $base->currency, $base->mode, $base->details);
    }
}
