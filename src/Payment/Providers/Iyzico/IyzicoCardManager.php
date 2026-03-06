<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Iyzipay\Model\Card;
use Iyzipay\Model\CardInformation;
use Iyzipay\Model\CardList;
use Iyzipay\Request\CreateCardRequest;
use Iyzipay\Request\DeleteCardRequest;
use Iyzipay\Request\RetrieveCardListRequest;
use Throwable;

final class IyzicoCardManager
{
    public function __construct(
        private readonly IyzicoSupport $support,
    ) {}

    public function listStoredCards(string $cardUserKey): array
    {
        if ($cardUserKey === '') {
            return [];
        }

        if ($this->support->mockMode() || $this->support->missingCredentials() !== []) {
            return [];
        }

        try {
            $request = new RetrieveCardListRequest;
            $request->setConversationId((string) uniqid('iyz_cards_', true));
            $request->setCardUserKey($cardUserKey);

            $response = CardList::retrieve($request, $this->support->options());

            if (! $this->support->isSuccessfulResponse($response)) {
                return [];
            }

            $payload = $this->support->decodeRawPayload($response);

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

        if ($this->support->mockMode()) {
            return true;
        }

        if ($this->support->missingCredentials() !== []) {
            return false;
        }

        try {
            $request = new DeleteCardRequest;
            $request->setConversationId((string) uniqid('iyz_card_delete_', true));
            $request->setCardUserKey($cardUserKey);
            $request->setCardToken($cardToken);

            $response = Card::delete($request, $this->support->options());

            return $this->support->isSuccessfulResponse($response);
        } catch (Throwable) {
            return false;
        }
    }

    public function ensureRemoteCardTokens(array $details): array
    {
        $paymentMethod = $details['payment_method'] ?? [];

        if (! is_array($paymentMethod)) {
            $paymentMethod = [];
        }

        $providerCardToken = $paymentMethod['provider_card_token'] ?? null;
        $providerCustomerToken = $paymentMethod['provider_customer_token'] ?? null;
        $hasCardToken = is_string($providerCardToken) && trim($providerCardToken) !== '';
        $hasCardUserKey = is_string($providerCustomerToken) && trim($providerCustomerToken) !== '';

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

        $customerDetails = $details['customer'] ?? [];
        $email = trim((string) ($paymentMethod['email'] ?? (is_array($customerDetails) ? ($customerDetails['email'] ?? '') : '')));

        if ($email === '' || $this->support->mockMode() || $this->support->missingCredentials() !== []) {
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

            $response = Card::create($request, $this->support->options());

            if (! $this->support->isSuccessfulResponse($response)) {
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

    public function cardPayload(array $details): array
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
}
