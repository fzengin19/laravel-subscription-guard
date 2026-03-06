<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Transaction extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'provider',
        'provider_transaction_id',
        'provider_refund_id',
        'idempotency_key',
        'type',
        'status',
        'amount',
        'tax_amount',
        'tax_rate',
        'discount_amount',
        'refunded_amount',
        'currency',
        'provider_currency',
        'exchange_rate',
        'fee',
        'retry_count',
        'next_retry_at',
        'last_retry_at',
        'failure_reason',
        'processed_at',
        'refunded_at',
        'provider_response',
        'coupon_id',
        'discount_id',
    ];

    public function markFailed(
        string $reason,
        int $retryCount,
        ?Carbon $nextRetryAt = null,
        mixed $providerResponse = null,
        bool $replaceProviderResponse = false,
    ): void {
        $this->setAttribute('retry_count', $retryCount);
        $this->setAttribute('status', 'failed');
        $this->setAttribute('failure_reason', $reason);
        $this->setAttribute('last_retry_at', now());
        $this->setAttribute('next_retry_at', $nextRetryAt);
        $this->setAttribute('processed_at', now());

        if ($replaceProviderResponse || $providerResponse !== null) {
            $this->setAttribute('provider_response', $providerResponse);
        }

        $this->save();
    }

    public function markProcessing(): void
    {
        $this->setAttribute('status', 'processing');
        $this->setAttribute('failure_reason', null);
        $this->setAttribute('last_retry_at', now());
        $this->setAttribute('next_retry_at', null);
        $this->setAttribute('processed_at', null);
        $this->save();
    }

    public function markRetrying(): void
    {
        $this->setAttribute('status', 'retrying');
        $this->setAttribute('last_retry_at', now());
        $this->save();
    }

    public function markProcessed(
        ?string $transactionId,
        mixed $providerResponse = null,
        bool $replaceProviderResponse = false,
    ): void {
        $this->setAttribute('status', 'processed');
        $this->setAttribute('provider_transaction_id', $transactionId);
        $this->setAttribute('failure_reason', null);
        $this->setAttribute('processed_at', now());

        if ($replaceProviderResponse || $providerResponse !== null) {
            $this->setAttribute('provider_response', $providerResponse);
        }

        $this->save();
    }

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
            'refunded_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'last_retry_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
