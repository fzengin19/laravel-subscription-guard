<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Iyzipay\Options as IyzipayOptions;

final class IyzicoSupport
{
    public function mockMode(): bool
    {
        return (bool) ($this->config()['mock'] ?? false);
    }

    public function missingCredentials(): array
    {
        $config = $this->config();
        $missing = [];

        if (trim((string) ($config['api_key'] ?? '')) === '') {
            $missing[] = 'api_key';
        }

        if (trim((string) ($config['secret_key'] ?? '')) === '') {
            $missing[] = 'secret_key';
        }

        return $missing;
    }

    public function config(): array
    {
        $config = config('subscription-guard.providers.drivers.iyzico', []);

        return is_array($config) ? $config : [];
    }

    public function options(): IyzipayOptions
    {
        $config = $this->config();
        $options = new IyzipayOptions;
        $options->setApiKey((string) ($config['api_key'] ?? ''));
        $options->setSecretKey((string) ($config['secret_key'] ?? ''));
        $options->setBaseUrl((string) ($config['base_url'] ?? 'https://sandbox-api.iyzipay.com'));

        return $options;
    }

    public function isSuccessfulResponse(object $response): bool
    {
        $status = method_exists($response, 'getStatus') ? strtolower((string) $response->getStatus()) : '';

        return $status === 'success';
    }

    public function responseError(object $response, string $fallback): string
    {
        if (method_exists($response, 'getErrorMessage')) {
            $message = trim((string) $response->getErrorMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    public function decodeRawPayload(object $response): array
    {
        if (! method_exists($response, 'getRawResult')) {
            return [];
        }

        $raw = $response->getRawResult();

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function refundableTransactionId(array $payload, string $fallback): string
    {
        foreach (['itemTransactions', 'paymentItems'] as $key) {
            $items = $payload[$key] ?? null;

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $transactionId = trim((string) ($item['paymentTransactionId'] ?? ''));

                if ($transactionId !== '') {
                    return $transactionId;
                }
            }
        }

        $paymentId = trim((string) ($payload['paymentId'] ?? ''));

        return $paymentId !== '' ? $paymentId : $fallback;
    }

    public function pricingPlanRecurrenceCount(int $recurrenceCount): ?int
    {
        return $recurrenceCount > 0 ? $recurrenceCount : null;
    }
}
