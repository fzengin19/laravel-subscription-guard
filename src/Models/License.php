<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class License extends Model
{
    use SoftDeletes;

    protected $guarded = [
        'id',
        'status',
        'expires_at',
        'grace_ends_at',
        'heartbeat_at',
        'domain',
        'feature_overrides',
        'limit_overrides',
        'max_activations',
        'current_activations',
    ];

    protected function casts(): array
    {
        return [
            'feature_overrides' => 'array',
            'limit_overrides' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model', 'App\\Models\\User'), 'user_id');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenseActivation::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(LicenseUsage::class);
    }
}
