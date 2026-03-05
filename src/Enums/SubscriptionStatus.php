<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Enums;

enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Cancelled = 'cancelled';
    case PastDue = 'past_due';
    case Failed = 'failed';
    case Paused = 'paused';
    case Suspended = 'suspended';

    public static function normalize(mixed $status): ?self
    {
        if (! is_scalar($status)) {
            return null;
        }

        return match (strtolower(trim((string) $status))) {
            'pending' => self::Pending,
            'active', 'upgraded' => self::Active,
            'past_due', 'unpaid' => self::PastDue,
            'failed' => self::Failed,
            'paused' => self::Paused,
            'suspended' => self::Suspended,
            'canceled', 'cancelled', 'expired' => self::Cancelled,
            default => null,
        };
    }
}
