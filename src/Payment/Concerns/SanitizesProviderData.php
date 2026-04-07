<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Concerns;

trait SanitizesProviderData
{
    /** @var list<string> */
    private static array $sensitiveKeys = [
        'card_number', 'cardNumber', 'pan',
        'cvc', 'cvv', 'cvv2', 'cvc2', 'security_code',
        'expire_month', 'expireMonth', 'expire_year', 'expireYear',
        'card_holder_name', 'cardHolderName',
        'payment_card', 'paymentCard',
    ];

    public function sanitizeProviderResponse(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (in_array($key, self::$sensitiveKeys, true)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->sanitizeProviderResponse($value) : $value;
        }

        return $result;
    }

    public function sanitizeExceptionMessage(string $message): string
    {
        $sanitized = preg_replace(
            ['/\/[^\s:]+\.php/', '/on line \d+/', '/Stack trace:.*$/s'],
            ['[redacted-path]', '', ''],
            $message
        );

        return $sanitized ?? $message;
    }
}
