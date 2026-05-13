<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Support;

final class Json
{
    /**
     * Deterministically hash any value to a 64-char hex SHA-256.
     *
     * Uses JSON_THROW_ON_ERROR + JSON_INVALID_UTF8_SUBSTITUTE so binary or
     * invalid-UTF8 inputs do not collapse to hash('sha256', '') the way
     * (string) json_encode(false) does. Falls back to serialize() if JSON
     * encoding still fails (cycles, resources, closures).
     */
    public static function safeHash(mixed $value): string
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (\JsonException) {
            try {
                $encoded = 'serialize:'.serialize($value);
            } catch (\Throwable $exception) {
                $encoded = 'fallback:'.$exception::class.':'.spl_object_id((object) $value);
            }
        }

        return hash('sha256', $encoded);
    }
}
