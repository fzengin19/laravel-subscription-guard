<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Card;
use Iyzipay\Model\CardInformation;
use Iyzipay\Model\CardList;
use Iyzipay\Model\CheckoutFormInitialize;
use Iyzipay\Model\Customer;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\Refund;
use Iyzipay\Model\Subscription\SubscriptionCancel;
use Iyzipay\Model\Subscription\SubscriptionCreate;
use Iyzipay\Model\Subscription\SubscriptionUpgrade;
use Iyzipay\Options as IyzipayOptions;
use Iyzipay\Request\CreateCardRequest;
use Iyzipay\Request\CreateCheckoutFormInitializeRequest;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\CreateRefundRequest;
use Iyzipay\Request\DeleteCardRequest;
use Iyzipay\Request\RetrieveCardListRequest;
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
use Throwable;

final class IyzicoProvider implements PaymentProviderInterface
{
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
                return new PaymentResponse(false, null, null, null, null, $details, 'Iyzico live payment failed: '.$exception->getMessage());
            }
        }

        if ($mode === 'checkout_form') {
            $token = 'cf_'.sha1((string) json_encode($details));
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
            $conversationId = (string) ($details['conversation_id'] ?? sha1((string) json_encode($details)));
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

        $txnId = 'txn_non3ds_'.sha1((string) json_encode($details).':'.$amount);

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
                $request->setConversationId((string) ($transactionId !== '' ? $transactionId : uniqid('iyz_ref_', true)));
                $request->setPaymentTransactionId($transactionId);
                $request->setPrice($this->money($amount));
                $request->setCurrency((string) ($this->config()['currency'] ?? 'TRY'));

                $response = Refund::create($request, $this->options());
                $payload = $this->decodeRawPayload($response);

                if (! $this->isSuccessfulResponse($response)) {
                    return new RefundResponse(false, null, $payload, $this->responseError($response, 'Iyzico refund failed.'));
                }

                $refundId = is_scalar($payload['paymentTransactionId'] ?? null) ? (string) $payload['paymentTransactionId'] : null;

                return new RefundResponse(true, $refundId, $payload);
            } catch (Throwable $exception) {
                return new RefundResponse(false, null, ['transaction_id' => $transactionId], 'Iyzico live refund failed: '.$exception->getMessage());
            }
        }

        return new RefundResponse(true, 'rf_'.sha1($transactionId.':'.(string) $amount), ['transaction_id' => $transactionId, 'amount' => (float) $amount]);
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
                $request->setConversationId((string) ($details['conversation_id'] ?? uniqid('iyz_sub_', true)));
                $request->setPricingPlanReferenceCode($pricingPlanReference);
                $request->setSubscriptionInitialStatus((string) ($details['subscription_initial_status'] ?? 'ACTIVE'));
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
                return new SubscriptionResponse(false, null, null, ['plan' => $plan, 'details' => $details], 'Iyzico live subscription create failed: '.$exception->getMessage());
            }
        }

        $seed = (string) ($plan['slug'] ?? $plan['id'] ?? uniqid('sub', true));
        $subscriptionId = 'iyz_sub_'.sha1($seed);

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
                $request->setConversationId((string) uniqid('iyz_cancel_', true));
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
                $request->setConversationId((string) uniqid('iyz_upgrade_', true));
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
                return new SubscriptionResponse(false, null, null, ['mode' => $mode], 'Iyzico live subscription upgrade failed: '.$exception->getMessage());
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
            metadata: $payload,
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
        return (bool) ($this->config()['mock'] ?? false);
    }

    private function missingCredentials(): array
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

    private function config(): array
    {
        $config = config('subscription-guard.providers.drivers.iyzico', []);

        return is_array($config) ? $config : [];
    }

    private function livePay(int|float|string $amount, array $details, string $mode): PaymentResponse
    {
        $options = $this->options();

        if ($mode === 'checkout_form') {
            $request = new CreateCheckoutFormInitializeRequest;
            $request->setConversationId((string) ($details['conversation_id'] ?? uniqid('iyz_cf_', true)));
            $request->setPrice($this->money($amount));
            $request->setPaidPrice($this->money($amount));
            $request->setCurrency((string) ($details['currency'] ?? 'TRY'));
            $request->setBasketId((string) ($details['basket_id'] ?? uniqid('basket_', true)));
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
        $request->setConversationId((string) ($details['conversation_id'] ?? uniqid('iyz_pay_', true)));
        $request->setPrice($this->money($amount));
        $request->setPaidPrice($this->money($amount));
        $request->setCurrency((string) ($details['currency'] ?? 'TRY'));
        $request->setInstallment((int) ($details['installment'] ?? 1));
        $request->setPaymentChannel((string) ($details['payment_channel'] ?? 'WEB'));
        $request->setPaymentGroup((string) ($details['payment_group'] ?? 'PRODUCT'));
        $request->setBasketId((string) ($details['basket_id'] ?? uniqid('basket_', true)));
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
        $redirectUrl = $mode === '3ds' && is_scalar($payload['threeDSHtmlContent'] ?? null) ? (string) $payload['threeDSHtmlContent'] : null;

        return new PaymentResponse(true, $paymentId, $redirectUrl, null, null, $payload);
    }

    private function options(): IyzipayOptions
    {
        $config = $this->config();
        $options = new IyzipayOptions;
        $options->setApiKey((string) ($config['api_key'] ?? ''));
        $options->setSecretKey((string) ($config['secret_key'] ?? ''));
        $options->setBaseUrl((string) ($config['base_url'] ?? 'https://sandbox-api.iyzipay.com'));

        return $options;
    }

    private function isSuccessfulResponse(object $response): bool
    {
        $status = method_exists($response, 'getStatus') ? strtolower((string) $response->getStatus()) : '';

        return $status === 'success';
    }

    private function responseError(object $response, string $fallback): string
    {
        if (method_exists($response, 'getErrorMessage')) {
            $message = trim((string) $response->getErrorMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    private function decodeRawPayload(object $response): array
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

    public function listStoredCards(string $cardUserKey): array
    {
        if ($cardUserKey === '') {
            return [];
        }

        if ($this->mockMode() || $this->missingCredentials() !== []) {
            return [];
        }

        try {
            $request = new RetrieveCardListRequest;
            $request->setConversationId((string) uniqid('iyz_cards_', true));
            $request->setCardUserKey($cardUserKey);

            $response = CardList::retrieve($request, $this->options());

            if (! $this->isSuccessfulResponse($response)) {
                return [];
            }

            $payload = $this->decodeRawPayload($response);

            return is_array($payload['cardDetails'] ?? null) ? $payload['cardDetails'] : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function deleteStoredCard(string $cardUserKey, string $cardToken): bool
    {
        if ($cardUserKey === '' || $cardToken === '') {
            return false;
        }

        if ($this->mockMode()) {
            return true;
        }

        if ($this->missingCredentials() !== []) {
            return false;
        }

        try {
            $request = new DeleteCardRequest;
            $request->setConversationId((string) uniqid('iyz_card_delete_', true));
            $request->setCardUserKey($cardUserKey);
            $request->setCardToken($cardToken);

            $response = Card::delete($request, $this->options());

            return $this->isSuccessfulResponse($response);
        } catch (Throwable) {
            return false;
        }
    }

    private function ensureRemoteCardTokens(array $details): array
    {
        $paymentMethod = $details['payment_method'] ?? [];

        if (! is_array($paymentMethod)) {
            $paymentMethod = [];
        }

        $hasCardToken = is_string($paymentMethod['provider_card_token'] ?? null) && trim((string) $paymentMethod['provider_card_token']) !== '';
        $hasCardUserKey = is_string($paymentMethod['provider_customer_token'] ?? null) && trim((string) $paymentMethod['provider_customer_token']) !== '';

        if ($hasCardToken && $hasCardUserKey) {
            return $details;
        }

        $cardData = $details['payment_card'] ?? $details['card'] ?? [];

        if (! is_array($cardData)) {
            return $details;
        }

        $cardNumber = trim((string) ($cardData['card_number'] ?? ''));
        $expireMonth = trim((string) ($cardData['expire_month'] ?? $cardData['expiry_month'] ?? ''));
        $expireYear = trim((string) ($cardData['expire_year'] ?? $cardData['expiry_year'] ?? ''));

        if ($cardNumber === '' || $expireMonth === '' || $expireYear === '') {
            return $details;
        }

        $email = trim((string) ($paymentMethod['email'] ?? $details['customer']['email'] ?? ''));

        if ($email === '' || $this->mockMode() || $this->missingCredentials() !== []) {
            return $details;
        }

        try {
            $request = new CreateCardRequest;
            $request->setConversationId((string) ($details['conversation_id'] ?? uniqid('iyz_card_', true)));
            $request->setExternalId((string) ($details['payable_id'] ?? uniqid('payable_', true)));
            $request->setEmail($email);

            $cardUserKey = trim((string) ($paymentMethod['provider_customer_token'] ?? $cardData['card_user_key'] ?? ''));

            if ($cardUserKey !== '') {
                $request->setCardUserKey($cardUserKey);
            }

            $cardInformation = new CardInformation;
            $cardInformation->setCardAlias((string) ($cardData['card_alias'] ?? 'Subscription Card'));
            $cardInformation->setCardNumber($cardNumber);
            $cardInformation->setExpireMonth($expireMonth);
            $cardInformation->setExpireYear($expireYear);
            $cardInformation->setCardHolderName((string) ($cardData['card_holder_name'] ?? ''));
            $request->setCard($cardInformation);

            $response = Card::create($request, $this->options());

            if (! $this->isSuccessfulResponse($response)) {
                return $details;
            }

            $remoteCardUserKey = method_exists($response, 'getCardUserKey') ? (string) $response->getCardUserKey() : '';
            $remoteCardToken = method_exists($response, 'getCardToken') ? (string) $response->getCardToken() : '';

            if ($remoteCardUserKey !== '') {
                $paymentMethod['provider_customer_token'] = $remoteCardUserKey;
                $cardData['card_user_key'] = $remoteCardUserKey;
            }

            if ($remoteCardToken !== '') {
                $paymentMethod['provider_card_token'] = $remoteCardToken;
                $paymentMethod['provider_method_id'] = $remoteCardToken;
                $cardData['card_token'] = $remoteCardToken;
            }

            $details['payment_method'] = $paymentMethod;
            $details['payment_card'] = $cardData;

            return $details;
        } catch (Throwable) {
            return $details;
        }
    }

    private function cardPayload(array $details): array
    {
        $paymentMethod = $details['payment_method'] ?? [];

        if (! is_array($paymentMethod)) {
            return [];
        }

        return [
            'provider_customer_token' => (string) ($paymentMethod['provider_customer_token'] ?? ''),
            'provider_card_token' => (string) ($paymentMethod['provider_card_token'] ?? ''),
            'provider_method_id' => (string) ($paymentMethod['provider_method_id'] ?? ''),
            'card_last_four' => (string) ($paymentMethod['card_last_four'] ?? ''),
            'card_brand' => (string) ($paymentMethod['card_brand'] ?? ''),
            'card_expiry' => (string) ($paymentMethod['card_expiry'] ?? ''),
        ];
    }

    private function paymentCard(array $details): PaymentCard
    {
        $cardData = $details['payment_card'] ?? $details['card'] ?? [];

        if (! is_array($cardData)) {
            $cardData = [];
        }

        $card = new PaymentCard;
        $card->setCardHolderName((string) ($cardData['card_holder_name'] ?? ''));
        $card->setCardNumber((string) ($cardData['card_number'] ?? ''));
        $card->setExpireMonth((string) ($cardData['expire_month'] ?? $cardData['expiry_month'] ?? ''));
        $card->setExpireYear((string) ($cardData['expire_year'] ?? $cardData['expiry_year'] ?? ''));
        $card->setCvc((string) ($cardData['cvc'] ?? ''));
        $card->setCardToken((string) ($cardData['card_token'] ?? ''));
        $card->setCardUserKey((string) ($cardData['card_user_key'] ?? ''));

        return $card;
    }

    private function subscriptionPaymentCard(array $details): array
    {
        $cardData = $details['payment_card'] ?? $details['card'] ?? [];

        if (! is_array($cardData)) {
            $cardData = [];
        }

        return [
            'cardHolderName' => (string) ($cardData['card_holder_name'] ?? ''),
            'cardNumber' => (string) ($cardData['card_number'] ?? ''),
            'expireYear' => (string) ($cardData['expire_year'] ?? $cardData['expiry_year'] ?? ''),
            'expireMonth' => (string) ($cardData['expire_month'] ?? $cardData['expiry_month'] ?? ''),
            'cvc' => (string) ($cardData['cvc'] ?? ''),
            'registerCard' => (int) ($cardData['register_card'] ?? 0),
            'cardAlias' => (string) ($cardData['card_alias'] ?? ''),
            'cardUserKey' => (string) ($cardData['card_user_key'] ?? ''),
        ];
    }

    private function customer(array $details): Customer
    {
        $customerData = $details['customer'] ?? [];

        if (! is_array($customerData)) {
            $customerData = [];
        }

        $customer = new Customer;
        $customer->setName((string) ($customerData['name'] ?? ''));
        $customer->setSurname((string) ($customerData['surname'] ?? ''));
        $customer->setIdentityNumber((string) ($customerData['identity_number'] ?? ''));
        $customer->setEmail((string) ($customerData['email'] ?? ''));
        $customer->setGsmNumber((string) ($customerData['gsm_number'] ?? ''));
        $customer->setShippingContactName((string) ($customerData['shipping_contact_name'] ?? ''));
        $customer->setShippingCity((string) ($customerData['shipping_city'] ?? ''));
        $customer->setShippingDistrict((string) ($customerData['shipping_district'] ?? ''));
        $customer->setShippingCountry((string) ($customerData['shipping_country'] ?? ''));
        $customer->setShippingAddress((string) ($customerData['shipping_address'] ?? ''));
        $customer->setShippingZipCode((string) ($customerData['shipping_zip_code'] ?? ''));
        $customer->setBillingContactName((string) ($customerData['billing_contact_name'] ?? ''));
        $customer->setBillingCity((string) ($customerData['billing_city'] ?? ''));
        $customer->setBillingDistrict((string) ($customerData['billing_district'] ?? ''));
        $customer->setBillingCountry((string) ($customerData['billing_country'] ?? ''));
        $customer->setBillingAddress((string) ($customerData['billing_address'] ?? ''));
        $customer->setBillingZipCode((string) ($customerData['billing_zip_code'] ?? ''));

        return $customer;
    }

    private function buyer(array $details): Buyer
    {
        $buyerData = $details['buyer'] ?? [];

        if (! is_array($buyerData)) {
            $buyerData = [];
        }

        $buyer = new Buyer;
        $buyer->setId((string) ($buyerData['id'] ?? uniqid('buyer_', true)));
        $buyer->setName((string) ($buyerData['name'] ?? ''));
        $buyer->setSurname((string) ($buyerData['surname'] ?? ''));
        $buyer->setIdentityNumber((string) ($buyerData['identity_number'] ?? ''));
        $buyer->setEmail((string) ($buyerData['email'] ?? ''));
        $buyer->setGsmNumber((string) ($buyerData['gsm_number'] ?? ''));
        $buyer->setRegistrationAddress((string) ($buyerData['registration_address'] ?? ''));
        $buyer->setCity((string) ($buyerData['city'] ?? ''));
        $buyer->setCountry((string) ($buyerData['country'] ?? ''));
        $buyer->setZipCode((string) ($buyerData['zip_code'] ?? ''));
        $buyer->setIp((string) ($buyerData['ip'] ?? request()->ip() ?? '127.0.0.1'));

        return $buyer;
    }

    private function address(array $details, string $key): Address
    {
        $addressData = $details[$key] ?? [];

        if (! is_array($addressData)) {
            $addressData = [];
        }

        $address = new Address;
        $address->setAddress((string) ($addressData['address'] ?? ''));
        $address->setZipCode((string) ($addressData['zip_code'] ?? ''));
        $address->setContactName((string) ($addressData['contact_name'] ?? ''));
        $address->setCity((string) ($addressData['city'] ?? ''));
        $address->setCountry((string) ($addressData['country'] ?? ''));

        return $address;
    }

    private function basketItems(array $details): array
    {
        $items = $details['basket_items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $basketItems = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $basketItem = new BasketItem;
            $basketItem->setId((string) ($item['id'] ?? 'item_'.$index));
            $basketItem->setPrice($this->money($item['price'] ?? 0));
            $basketItem->setName((string) ($item['name'] ?? 'Subscription Item'));
            $basketItem->setCategory1((string) ($item['category1'] ?? 'Subscription'));
            $basketItem->setCategory2((string) ($item['category2'] ?? ''));
            $basketItem->setItemType((string) ($item['item_type'] ?? 'VIRTUAL'));
            $basketItems[] = $basketItem;
        }

        return $basketItems;
    }

    private function money(int|float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
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
