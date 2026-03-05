<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentMethod extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'payable_type',
        'payable_id',
        'provider',
        'provider_method_id',
        'provider_card_token',
        'provider_customer_token',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_default' => 'bool',
            'is_active' => 'bool',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
