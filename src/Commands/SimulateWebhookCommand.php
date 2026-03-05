<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;

final class SimulateWebhookCommand extends Command
{
    protected $signature = 'subguard:simulate-webhook
        {provider : Payment provider (iyzico|paytr)}
        {event : Event type (example: payment.success)}
        {--event-id= : Explicit event id for idempotency checks}
        {--subscription-id= : Provider subscription reference}
        {--transaction-id= : Provider transaction/payment reference}
        {--amount=10.00 : Amount in major currency unit}';

    protected $description = 'Simulate provider webhook requests against package webhook endpoint';

    public function handle(PaymentManager $paymentManager, Kernel $kernel): int
    {
        $provider = strtolower(trim((string) $this->argument('provider')));
        $event = trim((string) $this->argument('event'));

        if (! $paymentManager->hasProvider($provider)) {
            $this->error(sprintf('Unsupported provider [%s].', $provider));

            return self::FAILURE;
        }

        if ($event === '') {
            $this->error('Event cannot be empty.');

            return self::FAILURE;
        }

        $eventId = $this->resolveEventId();
        $subscriptionId = $this->resolveSubscriptionId($provider);
        $transactionId = $this->resolveTransactionId($provider);
        $amount = $this->resolveAmount();

        [$payload, $signature] = match ($provider) {
            'paytr' => $this->buildPaytrPayload($event, $eventId, $subscriptionId, $transactionId, $amount),
            'iyzico' => $this->buildIyzicoPayload($event, $eventId, $subscriptionId, $transactionId),
            default => [[], ''],
        };

        if ($payload === []) {
            $this->error('Failed to build webhook payload.');

            return self::FAILURE;
        }

        $path = '/'.trim((string) config('subscription-guard.webhooks.prefix', 'subguard/webhooks'), '/').'/'.$provider;
        $headers = ['content-type' => 'application/json'];

        if ($signature !== '') {
            $signatureHeader = $this->signatureHeader($provider);
            $headers[$signatureHeader] = $signature;
        }

        $request = Request::create($path, 'POST', [], [], [], $this->serverHeaders($headers), json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();

        $this->line(sprintf('provider=%s event=%s event_id=%s status=%d', $provider, $event, $eventId, $status));

        if ($response instanceof Response && $response->headers->get('content-type') !== null && str_contains((string) $response->headers->get('content-type'), 'application/json')) {
            $content = (string) $response->getContent();
            $this->line('response='.$content);
        }

        if ($status >= 400) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveEventId(): string
    {
        $option = trim((string) $this->option('event-id'));

        if ($option !== '') {
            return $option;
        }

        return 'sim_'.Str::uuid()->toString();
    }

    private function resolveSubscriptionId(string $provider): string
    {
        $option = trim((string) $this->option('subscription-id'));

        if ($option !== '') {
            return $option;
        }

        return $provider.'_sub_'.bin2hex(random_bytes(4));
    }

    private function resolveTransactionId(string $provider): string
    {
        $option = trim((string) $this->option('transaction-id'));

        if ($option !== '') {
            return $option;
        }

        return $provider.'_txn_'.bin2hex(random_bytes(4));
    }

    private function resolveAmount(): float
    {
        $raw = trim((string) $this->option('amount'));
        $value = is_numeric($raw) ? (float) $raw : 10.0;

        return max(0.01, round($value, 2));
    }

    private function buildPaytrPayload(string $event, string $eventId, string $subscriptionId, string $transactionId, float $amount): array
    {
        $status = str_contains($event, 'fail') ? 'failed' : 'success';
        $totalAmount = (string) ((int) round($amount * 100));

        $payload = [
            'event_id' => $eventId,
            'merchant_oid' => $subscriptionId,
            'status' => $status,
            'total_amount' => $totalAmount,
            'payment_type' => 'card',
            'failed_reason_code' => $status === 'failed' ? '100' : '0',
            'failed_reason_msg' => $status === 'failed' ? 'simulation_failed' : null,
            'payment_id' => $transactionId,
        ];

        $merchantKey = (string) config('subscription-guard.providers.drivers.paytr.merchant_key', '');
        $merchantSalt = (string) config('subscription-guard.providers.drivers.paytr.merchant_salt', '');

        if ($merchantKey === '' || $merchantSalt === '') {
            return [$payload, ''];
        }

        $message = $subscriptionId.$merchantSalt.$status.$totalAmount;
        $signature = base64_encode(hash_hmac('sha256', $message, $merchantKey, true));

        return [$payload, $signature];
    }

    private function buildIyzicoPayload(string $event, string $eventId, string $subscriptionId, string $transactionId): array
    {
        $status = str_contains($event, 'fail') ? 'failure' : 'success';

        $payload = [
            'id' => $eventId,
            'event_type' => $event,
            'status' => $status,
            'paymentId' => $transactionId,
            'paymentConversationId' => $eventId,
            'subscriptionReferenceCode' => $subscriptionId,
        ];

        $secret = (string) config('subscription-guard.providers.drivers.iyzico.secret_key', '');

        if ($secret === '') {
            return [$payload, ''];
        }

        $message = $secret.$event.$transactionId.$eventId.$status;
        $signature = bin2hex(hash_hmac('sha256', $message, $secret, true));

        return [$payload, $signature];
    }

    private function signatureHeader(string $provider): string
    {
        $configured = config('subscription-guard.providers.drivers.'.$provider.'.signature_header');

        if (is_string($configured) && $configured !== '') {
            return strtolower($configured);
        }

        return $provider === 'iyzico' ? 'x-iyz-signature-v3' : 'x-paytr-signature';
    }

    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $key => $value) {
            $normalized = strtoupper(str_replace('-', '_', $key));
            $server[$normalized === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.$normalized] = $value;
        }

        return $server;
    }
}
