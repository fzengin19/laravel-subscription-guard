<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

class PaymentRequest
{
    public function __construct(
        public int|float|string $amount,
        public string $currency,
        public string $mode,
        public array $details = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            amount: $payload['amount'] ?? 0,
            currency: (string) ($payload['currency'] ?? 'TRY'),
            mode: (string) ($payload['mode'] ?? 'non_3ds'),
            details: $payload,
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'mode' => $this->mode,
            'details' => $this->details,
        ];
    }
}
