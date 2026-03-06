<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxCleanupRegistry;

it('runs registered cleanup callbacks in reverse order', function (): void {
    $calls = [];
    $registry = new IyzicoSandboxCleanupRegistry;

    $registry->register('first', function () use (&$calls): void {
        $calls[] = 'first';
    });

    $registry->register('second', function () use (&$calls): void {
        $calls[] = 'second';
    });

    $result = $registry->run();

    expect($calls)->toBe(['second', 'first'])
        ->and($result['cleanup_debt'])->toBe([]);
});

it('records cleanup debt instead of throwing immediately', function (): void {
    $registry = new IyzicoSandboxCleanupRegistry;
    $registry->register('subscription', function (): void {
        throw new RuntimeException('remote delete failed');
    });

    $result = $registry->run();

    expect($result['cleanup_debt'])->toHaveCount(1)
        ->and($result['cleanup_debt'][0]['label'])->toBe('subscription')
        ->and($result['cleanup_debt'][0]['reason'])->toContain('remote delete failed');
});
