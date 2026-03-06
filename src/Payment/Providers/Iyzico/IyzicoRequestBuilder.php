<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico;

use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Customer;
use Iyzipay\Model\PaymentCard;

final class IyzicoRequestBuilder
{
    public function paymentCard(array $details): PaymentCard
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

    public function subscriptionPaymentCard(array $details): array
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

    public function customer(array $details): Customer
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

    public function buyer(array $details, ?string $ip = null): Buyer
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
        $buyer->setIp((string) ($buyerData['ip'] ?? $ip ?? '127.0.0.1'));

        return $buyer;
    }

    public function address(array $details, string $key): Address
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

    public function basketItems(array $details): array
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

    public function money(int|float|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
