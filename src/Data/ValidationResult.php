<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Data;

final class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reason = null,
        public array $metadata = [],
    ) {}
}
