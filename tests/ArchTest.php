<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('keeps provider adapters free from db mutation and domain event dispatch', function (): void {
    $providerFiles = [
        __DIR__.'/../src/Payment/Providers/Iyzico/IyzicoProvider.php',
        __DIR__.'/../src/Payment/Providers/PayTR/PaytrProvider.php',
    ];

    $forbiddenPatterns = [
        '/\bDB::/m',
        '/\bSubscription::query\(/m',
        '/\bTransaction::query\(/m',
        '/\bPaymentMethod::query\(/m',
        '/\bEvent::dispatch\(/m',
        '/\bevent\(/m',
    ];

    foreach ($providerFiles as $file) {
        $content = (string) file_get_contents($file);

        foreach ($forbiddenPatterns as $pattern) {
            expect(preg_match($pattern, $content))->toBe(0, basename($file).' violates provider purity pattern '.$pattern);
        }
    }
});

it('keeps finalize webhook job provider-agnostic for domain branching', function (): void {
    $file = __DIR__.'/../src/Jobs/FinalizeWebhookEventJob.php';
    $content = (string) file_get_contents($file);

    $forbiddenProviderDomainBranches = [
        '/if\s*\([^\)]*iyzico[^\)]*\)\s*\{[^\}]*Subscription::/is',
        '/if\s*\([^\)]*paytr[^\)]*\)\s*\{[^\}]*Subscription::/is',
        '/if\s*\([^\)]*iyzico[^\)]*\)\s*\{[^\}]*Transaction::/is',
        '/if\s*\([^\)]*paytr[^\)]*\)\s*\{[^\}]*Transaction::/is',
    ];

    foreach ($forbiddenProviderDomainBranches as $pattern) {
        expect(preg_match($pattern, $content))->toBe(0, 'FinalizeWebhookEventJob violates provider-agnostic contract: '.$pattern);
    }
});
