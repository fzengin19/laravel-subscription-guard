<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Illuminate\Support\Facades\Log;
use Iyzipay\Model\Address;
use Iyzipay\Model\AmountBaseRefund;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\CheckoutFormInitialize;
use Iyzipay\Model\Customer;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\Refund;
use Iyzipay\Model\Subscription\SubscriptionCancel;
use Iyzipay\Model\Subscription\SubscriptionCreate;
use Iyzipay\Model\Subscription\SubscriptionUpgrade;
use Iyzipay\Options as IyzipayOptions;
use Iyzipay\Request\AmountBaseRefundRequest;
use Iyzipay\Request\CreateCheckoutFormInitializeRequest;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\CreateRefundRequest;
use Iyzipay\Request\Subscription\SubscriptionCancelRequest;
use Iyzipay\Request\Subscription\SubscriptionCreateRequest;
use Iyzipay\Request\Subscription\SubscriptionUpgradeRequest;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\PaymentProviderInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\PaymentResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\RefundResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\SubscriptionResponse;
use SubscriptionGuard\LaravelSubscriptionGuard\Data\WebhookResult;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Exceptions\UnsupportedProviderOperationException;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Concerns\SanitizesProviderData;
use Throwable;

final class IyzicoProvider implements PaymentProviderInterface
{
    use SanitizesProviderData;
    public function __construct(
        private readonly IyzicoRequestBuilder $requestBuilder,
        private readonly IyzicoCardManager $cardManager,
        private readonly IyzicoSupport $support,
    ) {}

    public function getName(): string
    {
        return 'iyzico';
    }

    public function managesOwnBilling(): bool
    {
        return true;
    }

    public function pay(int|float|string $amount, array $details): PaymentResponse
    {
        $mode = strtolower((string) ($details['mode'] ?? 'non_3ds'));

        if (! $this->mockMode()) {
            $missingCredentials = $this->missingCredentials();
            $failureReason = $missingCredentials === []
                ? null
                : 'Iyzico credentials are missing: '.implode(', ', $missingCredentials).'.';

            if ($failureReason !== null) {
                return new PaymentResponse(false, null, null, null, null, $details, $failureReason);
            }

            try {
                return $this->livePay($amount, $details, $mode);
            } catch (Throwable $exception) {
                return new PaymentResponse(false, null, null, null, null, $this->sanitizeProviderResponse($details), 'Iyzico live payment failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
            }
        }

        if ($mode === 'checkout_form') {
            $token = 'cf_'.substr(hash('sha256', (string) json_encode($details)), 0, 40);
            $txnId = 'txn_'.$token;
            $callbackUrl = $this->callbackUrl('checkout/callback');

            $response = new PaymentResponse(
                true,
                $txnId,
                null,
                $token,
                'https://sandbox-cpp.iyzipay.com/mock-checkout/'.$token,
                [
                    'mode' => 'checkout_form',
                    'callback_url' => $callbackUrl,
                ]
            );

            return $response;
        }

        if ($mode === '3ds') {
            $conversationId = (string) ($details['conversation_id'] ?? substr(hash('sha256', (string) json_encode($details)), 0, 40));
            $txnId = 'txn_3ds_'.$conversationId;
            $callbackUrl = $this->callbackUrl('3ds/callback');

            $response = new PaymentResponse(
                true,
                $txnId,
                'https://sandbox-api.iyzipay.com/mock/3ds-auth/'.$conversationId,
                null,
                null,
                [
                    'mode' => '3ds',
                    'callback_url' => $callbackUrl,
                ]
            );

            return $response;
        }

        $txnId = 'txn_non3ds_'.substr(hash('sha256', (string) json_encode($details).':'.$amount), 0, 40);

        $response = new PaymentResponse(
            true,
            $txnId,
            null,
            null,
            null,
            ['mode' => 'non_3ds']
        );

        return $response;
    }

    public function refund(string $transactionId, int|float|string $amount): RefundResponse
    {
        if (! $this->mockMode()) {
            $missingCredentials = $this->missingCredentials();

            if ($missingCredentials !== []) {
                return new RefundResponse(false, null, ['transaction_id' => $transactionId], 'Iyzico credentials are missing: '.implode(', ', $missingCredentials).'.');
            }

            try {
                $request = new CreateRefundRequest;
                $request->setConversationId((string) ($transactionId !== '' ? $transactionId : 'iyz_ref_'.bin2hex(random_bytes(8))));
                $request->setPaymentTransactionId($transactionId);
                $request->setPrice($this->money($amount));
                $request->setCurrency((string) ($this->config()['currency'] ?? 'TRY'));

                $response = Refund::create($request, $this->options());
                $payload = $this->decodeRawPayload($response);

                if (! $this->isSuccessfulResponse($response)) {
                    $fallbackResponse = $this->refundByPaymentId($transactionId, $amount);

                    if ($fallbackResponse !== null && $fallbackResponse->success) {
                        return $fallbackResponse;
                    }

                    return new RefundResponse(false, null, $payload, $this->responseError($response, 'Iyzico refund failed.'));
                }

                $refundId = $this->support->refundableTransactionId($payload, $transactionId);

                return new RefundResponse(true, $refundId, $payload);
            } catch (Throwable $exception) {
                return new RefundResponse(false, null, ['transaction_id' => $transactionId], 'Iyzico live refund failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
            }
        }

        return new RefundResponse(true, 'rf_'.substr(hash('sha256', $transactionId.':'.(string) $amount), 0, 40), ['transaction_id' => $transactionId, 'amount' => (float) $amount]);
    }

    private function refundByPaymentId(string $paymentId, int|float|string $amount): ?RefundResponse
    {
        if ($paymentId === '') {
            return null;
        }

        $request = new AmountBaseRefundRequest;
        $request->setConversationId('iyz_amount_ref_'.bin2hex(random_bytes(8)));
        $request->setPaymentId($paymentId);
        $request->setPrice((float) $this->money($amount));
        $request->setIp('127.0.0.1');

        $response = AmountBaseRefund::create($request, $this->options());
        $payload = $this->decodeRawPayload($response);

        if (! $this->isSuccessfulResponse($response)) {
            return new RefundResponse(false, null, $payload, $this->responseError($response, 'Iyzico amount-based refund failed.'));
        }

        $refundId = is_scalar($payload['paymentId'] ?? null) ? (string) $payload['paymentId'] : $paymentId;

        return new RefundResponse(true, $refundId, $payload);
    }

    public function createSubscription(array $plan, array $details): SubscriptionResponse
    {
        $pricingPlanReference = trim((string) ($plan['iyzico_pricing_plan_reference'] ?? ''));

        if ($pricingPlanReference === '') {
            return new SubscriptionResponse(false, null, null, ['plan' => $plan], 'Missing iyzico pricing plan reference. Run subguard:sync-plans first.');
        }

        if (! $this->mockMode()) {
            $missingCredentials = $this->missingCredentials();

            if ($missingCredentials !== []) {
                return new SubscriptionResponse(false, null, null, ['plan' => $plan, 'details' => $details], 'Iyzico credentials are missing: '.implode(', ', $missingCredentials).'.');
            }

            try {
                $details = $this->ensureRemoteCardTokens($details);

                $request = new SubscriptionCreateRequest;
                $request->setConversationId((string) ($details['conversation_id'] ?? 'iyz_sub_'.bin2hex(random_bytes(8))));
                $request->setPricingPlanReferenceCode($pricingPlanReference);
                $request->setSubscriptionInitialStatus((string) ($details['subscription_initial_status'] ?? 'PENDING'));
                $request->setPaymentCard($this->subscriptionPaymentCard($details));
                $request->setCustomer($this->customer($details));

                $response = SubscriptionCreate::create($request, $this->options());
                $payload = $this->decodeRawPayload($response);
                $cardPayload = $this->cardPayload($details);

                if ($cardPayload !== []) {
                    $payload['card'] = $cardPayload;
                }

                if (! $this->isSuccessfulResponse($response)) {
                    return new SubscriptionResponse(false, null, null, $payload, $this->responseError($response, 'Iyzico subscription create failed.'));
                }

                $subscriptionId = method_exists($response, 'getReferenceCode') ? (string) $response->getReferenceCode() : null;
                $status = method_exists($response, 'getSubscriptionStatus') ? (string) $response->getSubscriptionStatus() : null;

                return new SubscriptionResponse(true, $subscriptionId, $status !== '' ? strtolower($status) : SubscriptionStatus::Active->value, $payload);
            } catch (Throwable $exception) {
                return new SubscriptionResponse(false, null, null, $this->sanitizeProviderResponse(['plan' => $plan, 'details' => $details]), 'Iyzico live subscription create failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
            }
        }

        $seed = (string) ($plan['slug'] ?? $plan['id'] ?? 'sub_'.bin2hex(random_bytes(8)));
        $subscriptionId = 'iyz_sub_'.substr(hash('sha256', $seed), 0, 40);

        $response = new SubscriptionResponse(
            true,
            $subscriptionId,
            'active',
            [
                'provider' => 'iyzico',
                'mock' => true,
                'card' => $this->cardPayload($details),
            ]
        );

        return $response;
    }

    public function cancelSubscription(string $subscriptionId): bool
    {
        if (! $this->mockMode()) {
            if ($subscriptionId === '' || $this->missingCredentials() !== []) {
                return false;
            }

            try {
                $request = new SubscriptionCancelRequest;
                $request->setConversationId((string) 'iyz_cancel_'.bin2hex(random_bytes(8)));
                $request->setSubscriptionReferenceCode($subscriptionId);

                $response = SubscriptionCancel::cancel($request, $this->options());

                return $this->isSuccessfulResponse($response);
            } catch (Throwable) {
                return false;
            }
        }

        return $subscriptionId !== '';
    }

    public function upgradeSubscription(string $subscriptionId, int|string $newPlanId, string $mode = 'next_period'): SubscriptionResponse
    {
        if ($subscriptionId === '' || (string) $newPlanId === '' || ! in_array($mode, ['now', 'next_period'], true)) {
            return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Invalid upgrade request.');
        }

        if (! $this->mockMode()) {
            $missingCredentials = $this->missingCredentials();

            if ($missingCredentials !== []) {
                return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Iyzico credentials are missing: '.implode(', ', $missingCredentials).'.');
            }

            try {
                $request = new SubscriptionUpgradeRequest;
                $request->setConversationId((string) 'iyz_upgrade_'.bin2hex(random_bytes(8)));
                $request->setSubscriptionReferenceCode($subscriptionId);
                $request->setNewPricingPlanReferenceCode((string) $newPlanId);

                if ($mode === 'now') {
                    $request->setUpgradePeriod('NOW');
                }

                $response = SubscriptionUpgrade::update($request, $this->options());
                $payload = $this->decodeRawPayload($response);

                if (! $this->isSuccessfulResponse($response)) {
                    return new SubscriptionResponse(false, null, null, $payload, $this->responseError($response, 'Iyzico subscription upgrade failed.'));
                }

                $status = method_exists($response, 'getSubscriptionStatus') ? (string) $response->getSubscriptionStatus() : null;

                return new SubscriptionResponse(true, $subscriptionId, $status !== '' ? strtolower($status) : SubscriptionStatus::Active->value, $payload);
            } catch (Throwable $exception) {
                return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Iyzico live subscription upgrade failed: '.$this->sanitizeExceptionMessage($exception->getMessage()));
            }
        }

        return new SubscriptionResponse(true, $subscriptionId, SubscriptionStatus::Active->value, ['new_plan_id' => $newPlanId, 'mode' => $mode]);
    }

    public function chargeRecurring(array $subscription, int|float|string $amount, ?string $idempotencyKey = null): PaymentResponse
    {
        throw new UnsupportedProviderOperationException('iyzico manages recurring charges provider-side; local recurring charge is not supported.');
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        if ($this->mockMode()) {
            if (app()->environment('production')) {
                Log::channel((string) config('subscription-guard.logging.channel', 'subguard'))
                    ->critical('Iyzico webhook signature validation bypassed: mock mode is active in production.');
            }

            return true;
        }

        $secret = (string) ($this->config()['secret_key'] ?? '');

        if ($secret === '') {
            return false;
        }

        if ($signature === '') {
            return false;
        }

        $computed = $this->computeWebhookSignature($payload, $secret);

        if ($computed === '') {
            return false;
        }

        return hash_equals(strtolower($computed), strtolower($signature));
    }

    public function processWebhook(array $payload): WebhookResult
    {
        $eventId = $this->eventId($payload);
        $eventType = $this->eventType($payload);

        $subscriptionId = $this->extractString($payload, [
            'subscription_id',
            'subscriptionId',
            'subscription_reference',
            'subscription.referenceCode',
            'subscriptionReferenceCode',
            'data.subscription_id',
            'data.subscriptionId',
            'data.subscription_reference',
            'data.subscriptionReferenceCode',
        ]);

        $transactionId = $this->extractString($payload, [
            'payment_id',
            'paymentId',
            'conversation_id',
            'conversationId',
            'orderReferenceCode',
            'iyziReferenceCode',
            'referenceCode',
            'data.payment_id',
            'data.paymentId',
            'data.iyziReferenceCode',
        ]);

        $nextBillingDate = $this->extractString($payload, [
            'next_payment_date',
            'nextPaymentDate',
            'data.next_payment_date',
            'data.nextPaymentDate',
            'nextBillingDate',
        ]);

        $status = match ($eventType) {
            'subscription.created', 'subscription.order.success' => SubscriptionStatus::Active->value,
            'subscription.canceled', 'subscription.cancelled' => SubscriptionStatus::Cancelled->value,
            'subscription.order.failure' => SubscriptionStatus::PastDue->value,
            default => null,
        };

        return new WebhookResult(
            processed: true,
            eventId: $eventId,
            eventType: $eventType,
            duplicate: false,
            message: sprintf('Event [%s] parsed.', $eventType),
            subscriptionId: $subscriptionId,
            transactionId: $transactionId,
            amount: $this->extractFloat($payload, ['paid_price', 'price', 'amount', 'data.amount']),
            status: $status,
            nextBillingDate: $nextBillingDate,
            metadata: array_intersect_key($payload, array_flip([
                'iyziEventType', 'iyziReferenceCode', 'subscriptionReferenceCode',
                'orderReferenceCode', 'paymentId', 'status', 'price', 'currency',
                'token', 'paymentConversationId', 'event_type', 'id',
            ])),
        );
    }

    private function eventType(array $payload): string
    {
        return strtolower((string) ($this->extractString($payload, ['event_type', 'eventType', 'type']) ?? 'unknown'));
    }

    private function eventId(array $payload): string
    {
        return (string) ($this->extractString($payload, ['event_id', 'eventId', 'id', 'referenceCode'])
            ?? hash('sha256', (string) json_encode($payload)));
    }

    private function extractString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function extractFloat(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function mockMode(): bool
    {
        return $this->support->mockMode();
    }

    private function missingCredentials(): array
    {
        return $this->support->missingCredentials();
    }

    private function config(): array
    {
        return $this->support->config();
    }

    private function livePay(int|float|string $amount, array $details, string $mode): PaymentResponse
    {
        $options = $this->options();

        if ($mode === 'checkout_form') {
            $request = new CreateCheckoutFormInitializeRequest;
            $request->setConversationId((string) ($details['conversation_id'] ?? 'iyz_cf_'.bin2hex(random_bytes(8))));
            $request->setPrice($this->money($amount));
            $request->setPaidPrice($this->money($amount));
            $request->setCurrency((string) ($details['currency'] ?? 'TRY'));
            $request->setBasketId((string) ($details['basket_id'] ?? 'basket_'.bin2hex(random_bytes(8))));
            $request->setCallbackUrl($this->callbackUrl('checkout/callback'));
            $request->setPaymentGroup((string) ($details['payment_group'] ?? 'PRODUCT'));
            $request->setBuyer($this->buyer($details));
            $request->setShippingAddress($this->address($details, 'shipping_address'));
            $request->setBillingAddress($this->address($details, 'billing_address'));
            $request->setBasketItems($this->basketItems($details));

            $response = CheckoutFormInitialize::create($request, $options);
            $payload = $this->decodeRawPayload($response);

            if (! $this->isSuccessfulResponse($response)) {
                return new PaymentResponse(false, null, null, null, null, $payload, $this->responseError($response, 'Iyzico checkout form initialization failed.'));
            }

            $token = is_scalar($payload['token'] ?? null) ? (string) $payload['token'] : null;
            $checkoutUrl = is_scalar($payload['paymentPageUrl'] ?? null) ? (string) $payload['paymentPageUrl'] : null;

            return new PaymentResponse(true, null, null, $token, $checkoutUrl, $payload);
        }

        $request = new CreatePaymentRequest;
        $request->setConversationId((string) ($details['conversation_id'] ?? 'iyz_pay_'.bin2hex(random_bytes(8))));
        $request->setPrice($this->money($amount));
        $request->setPaidPrice($this->money($amount));
        $request->setCurrency((string) ($details['currency'] ?? 'TRY'));
        $request->setInstallment((int) ($details['installment'] ?? 1));
        $request->setPaymentChannel((string) ($details['payment_channel'] ?? 'WEB'));
        $request->setPaymentGroup((string) ($details['payment_group'] ?? 'PRODUCT'));
        $request->setBasketId((string) ($details['basket_id'] ?? 'basket_'.bin2hex(random_bytes(8))));
        $request->setPaymentCard($this->paymentCard($details));
        $request->setBuyer($this->buyer($details));
        $request->setShippingAddress($this->address($details, 'shipping_address'));
        $request->setBillingAddress($this->address($details, 'billing_address'));
        $request->setBasketItems($this->basketItems($details));

        if ($mode === '3ds') {
            $request->setCallbackUrl($this->callbackUrl('3ds/callback'));
        }

        $response = Payment::create($request, $options);
        $payload = $this->decodeRawPayload($response);

        if (! $this->isSuccessfulResponse($response)) {
            return new PaymentResponse(false, null, null, null, null, $payload, $this->responseError($response, 'Iyzico payment failed.'));
        }

        $paymentId = method_exists($response, 'getPaymentId') ? (string) $response->getPaymentId() : null;
        $transactionId = $this->support->refundableTransactionId($payload, (string) $paymentId);
        $redirectUrl = $mode === '3ds' && is_scalar($payload['threeDSHtmlContent'] ?? null) ? (string) $payload['threeDSHtmlContent'] : null;

        return new PaymentResponse(true, $transactionId, $redirectUrl, null, null, $payload);
    }

    private function options(): IyzipayOptions
    {
        return $this->support->options();
    }

    private function isSuccessfulResponse(object $response): bool
    {
        return $this->support->isSuccessfulResponse($response);
    }

    private function responseError(object $response, string $fallback): string
    {
        return $this->support->responseError($response, $fallback);
    }

    private function decodeRawPayload(object $response): array
    {
        return $this->support->decodeRawPayload($response);
    }

    public function listStoredCards(string $cardUserKey): array
    {
        return $this->cardManager->listStoredCards($cardUserKey);
    }

    public function deleteStoredCard(string $cardUserKey, string $cardToken): bool
    {
        return $this->cardManager->deleteStoredCard($cardUserKey, $cardToken);
    }

    private function ensureRemoteCardTokens(array $details): array
    {
        return $this->cardManager->ensureRemoteCardTokens($details);
    }

    private function cardPayload(array $details): array
    {
        return $this->cardManager->cardPayload($details);
    }

    private function paymentCard(array $details): PaymentCard
    {
        return $this->requestBuilder->paymentCard($details);
    }

    private function subscriptionPaymentCard(array $details): array
    {
        return $this->requestBuilder->subscriptionPaymentCard($details);
    }

    private function customer(array $details): Customer
    {
        return $this->requestBuilder->customer($details);
    }

    private function buyer(array $details): Buyer
    {
        return $this->requestBuilder->buyer($details, request()->ip());
    }

    private function address(array $details, string $key): Address
    {
        return $this->requestBuilder->address($details, $key);
    }

    private function basketItems(array $details): array
    {
        return $this->requestBuilder->basketItems($details);
    }

    private function money(int|float|string $amount): string
    {
        return $this->requestBuilder->money($amount);
    }

    private function computeWebhookSignature(array $payload, string $secret): string
    {
        $eventType = (string) ($this->extractString($payload, ['iyziEventType', 'event_type', 'eventType', 'type']) ?? '');
        $status = (string) ($this->extractString($payload, ['status']) ?? '');
        $paymentConversationId = (string) ($this->extractString($payload, ['paymentConversationId', 'conversation_id', 'conversationId']) ?? '');
        $paymentId = (string) ($this->extractString($payload, ['paymentId', 'payment_id', 'iyziPaymentId']) ?? '');
        $token = (string) ($this->extractString($payload, ['token']) ?? '');

        $subscriptionReferenceCode = (string) ($this->extractString($payload, ['subscriptionReferenceCode', 'subscription_reference_code']) ?? '');
        $orderReferenceCode = (string) ($this->extractString($payload, ['orderReferenceCode', 'order_reference_code']) ?? '');
        $customerReferenceCode = (string) ($this->extractString($payload, ['customerReferenceCode', 'customer_reference_code']) ?? '');
        $merchantId = (string) ($this->config()['merchant_id'] ?? '');

        if ($subscriptionReferenceCode !== '' && $orderReferenceCode !== '' && $customerReferenceCode !== '' && $merchantId !== '') {
            $message = $merchantId.$secret.$eventType.$subscriptionReferenceCode.$orderReferenceCode.$customerReferenceCode;

            return bin2hex(hash_hmac('sha256', $message, $secret, true));
        }

        if ($token !== '') {
            $message = $secret.$eventType.$paymentId.$token.$paymentConversationId.$status;

            return bin2hex(hash_hmac('sha256', $message, $secret, true));
        }

        if ($eventType !== '' && $paymentId !== '' && $paymentConversationId !== '' && $status !== '') {
            $message = $secret.$eventType.$paymentId.$paymentConversationId.$status;

            return bin2hex(hash_hmac('sha256', $message, $secret, true));
        }

        return '';
    }

    private function callbackUrl(string $path): string
    {
        $custom = trim((string) ($this->config()['callback_url'] ?? ''));
        $segment = ltrim($path, '/');

        if ($custom !== '' && $this->isValidHttpUrl($custom)) {
            return rtrim($custom, '/').'/'.$segment;
        }

        $prefix = trim((string) config('subscription-guard.webhooks.prefix', 'subguard/webhooks'), '/');

        return url($prefix.'/iyzico/'.$segment);
    }

    private function isValidHttpUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
