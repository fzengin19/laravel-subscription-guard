<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $guarded = ['id', 'current_uses', 'is_active'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'bool',
            'metadata' => 'array',
            'value' => 'float',
            'min_purchase_amount' => 'float',
            'max_discount_amount' => 'float',
        ];
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }
}
