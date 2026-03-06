<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live;

use InvalidArgumentException;

final class IyzicoSandboxFixtures
{
    private const CARD_NUMBERS = [
        'success_debit_tr' => '5890040000000016',
        'success_foreign_credit' => '5400010000000004',
        'success_no_cancel_refund' => '5406670000000009',
        'fail_insufficient_funds' => '4111111111111129',
        'fail_do_not_honour' => '4129111111111111',
        'fail_invalid_transaction' => '4128111111111112',
        'fail_lost_card' => '4127111111111113',
        'fail_stolen_card' => '4126111111111114',
        'fail_expired_card' => '4125111111111115',
        'fail_invalid_cvc2' => '4124111111111116',
        'fail_not_permitted_to_cardholder' => '4123111111111117',
        'fail_not_permitted_to_terminal' => '4122111111111118',
        'fail_fraud_suspect' => '4121111111111119',
        'fail_pickup_card' => '4120111111111110',
        'fail_general_error' => '4130111111111118',
        'success_mdstatus_0' => '4131111111111117',
        'success_mdstatus_4' => '4141111111111115',
        'fail_3ds_initialize' => '4151111111111112',
    ];

    public static function card(string $fixture): array
    {
        if (! array_key_exists($fixture, self::CARD_NUMBERS)) {
            throw new InvalidArgumentException('Unknown iyzico sandbox card fixture');
        }

        $expiry = now()->addYear();

        return [
            'fixture' => $fixture,
            'card_holder_name' => 'Phase Eight Sandbox',
            'card_number' => self::CARD_NUMBERS[$fixture],
            'expire_month' => $expiry->format('m'),
            'expire_year' => $expiry->format('Y'),
            'cvc' => '123',
        ];
    }

    public static function paymentPayload(string $fixture, IyzicoSandboxRunContext $context, array $overrides = []): array
    {
        $email = self::sandboxEmail('buyer', $context);

        return array_replace_recursive([
            'conversation_id' => $context->scopedValue('conversation'),
            'basket_id' => $context->scopedValue('basket'),
            'mode' => 'non_3ds',
            'payment_card' => self::card($fixture),
            'buyer' => [
                'id' => $context->scopedValue('buyer'),
                'name' => 'Phase',
                'surname' => 'Eight',
                'identity_number' => '11111111111',
                'email' => $email,
                'gsm_number' => '905350000000',
                'registration_address' => 'Sandbox Test Address',
                'city' => 'Istanbul',
                'country' => 'Turkey',
                'zip_code' => '34000',
            ],
            'shipping_address' => [
                'contact_name' => 'Phase Eight',
                'city' => 'Istanbul',
                'country' => 'Turkey',
                'address' => 'Sandbox Shipping Address',
                'zip_code' => '34000',
            ],
            'billing_address' => [
                'contact_name' => 'Phase Eight',
                'city' => 'Istanbul',
                'country' => 'Turkey',
                'address' => 'Sandbox Billing Address',
                'zip_code' => '34000',
            ],
            'basket_items' => [[
                'id' => $context->scopedValue('item'),
                'price' => 10.0,
                'name' => 'Phase Eight Sandbox Item',
                'category1' => 'Subscription',
                'item_type' => 'VIRTUAL',
            ]],
        ], $overrides);
    }

    public static function subscriptionPayload(string $fixture, IyzicoSandboxRunContext $context, int|string $payableId, array $overrides = []): array
    {
        $email = self::sandboxEmail('customer', $context);
        $card = self::card($fixture);

        return array_replace_recursive([
            'conversation_id' => $context->scopedValue('subscription'),
            'payable_id' => $payableId,
            'payment_card' => $card,
            'payment_method' => [
                'email' => $email,
                'is_default' => true,
                'card_holder_name' => $card['card_holder_name'],
                'card_last_four' => substr($card['card_number'], -4),
                'card_expiry' => $card['expire_month'].'/'.$card['expire_year'],
            ],
            'customer' => [
                'name' => 'Phase',
                'surname' => 'Eight',
                'identity_number' => '11111111111',
                'email' => $email,
                'gsm_number' => '905350000000',
                'shipping_contact_name' => 'Phase Eight',
                'shipping_city' => 'Istanbul',
                'shipping_district' => 'Kadikoy',
                'shipping_country' => 'Turkey',
                'shipping_address' => 'Sandbox Shipping Address',
                'shipping_zip_code' => '34000',
                'billing_contact_name' => 'Phase Eight',
                'billing_city' => 'Istanbul',
                'billing_district' => 'Kadikoy',
                'billing_country' => 'Turkey',
                'billing_address' => 'Sandbox Billing Address',
                'billing_zip_code' => '34000',
            ],
        ], $overrides);
    }

    private static function sandboxEmail(string $prefix, IyzicoSandboxRunContext $context): string
    {
        $suffix = substr(hash('sha256', $context->runId().$prefix), 0, 12);

        return sprintf('phase8%s%s@example.com', $prefix, $suffix);
    }
}
