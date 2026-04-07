<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookCall extends Model
{
    protected $guarded = ['id', 'processed_at', 'failure_reason'];

    public function markFailed(string $message): void
    {
        $this->setAttribute('status', 'failed');
        $this->setAttribute('error_message', $message);
        $this->setAttribute('processed_at', now());
        $this->save();
    }

    public function markProcessed(?string $message = null): void
    {
        $this->setAttribute('status', 'processed');
        $this->setAttribute('processed_at', now());
        $this->setAttribute('error_message', $message);
        $this->save();
    }

    public function resetForRetry(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }

        $this->setAttribute('status', 'pending');
        $this->setAttribute('error_message', null);
        $this->setAttribute('processed_at', null);
        $this->save();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
