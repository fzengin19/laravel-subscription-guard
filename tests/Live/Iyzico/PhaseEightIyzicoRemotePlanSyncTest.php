<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxRunContext;

beforeEach(function (): void {
    IyzicoSandboxGate::skipUnlessConfigured($this);
});

it('syncs remote iyzico product and pricing plan references', function (): void {
    $context = IyzicoSandboxRunContext::create('remote-plan-sync');
    $plan = phaseEightCreateIyzicoRemotePlan($context, 'basic', 99.0);

    $result = IyzicoSandboxGate::runWithTransientRetry(function (): array {
        $exitCode = Artisan::call('subguard:sync-plans', [
            '--provider' => 'iyzico',
            '--remote' => true,
        ]);

        return [
            'status' => $exitCode === 0 ? 'success' : 'failure',
            'errorMessage' => Artisan::output(),
        ];
    });

    $plan->refresh();

    expect($result['status'])->toBe('success')
        ->and((string) $plan->getAttribute('iyzico_product_reference'))->not->toBe('')
        ->and((string) $plan->getAttribute('iyzico_pricing_plan_reference'))->not->toBe('');
});

it('reruns remote sync idempotently when references already exist', function (): void {
    $context = IyzicoSandboxRunContext::create('remote-plan-sync-idempotent');
    $plan = phaseEightCreateIyzicoRemotePlan($context, 'pro', 149.0);

    Artisan::call('subguard:sync-plans', [
        '--provider' => 'iyzico',
        '--remote' => true,
    ]);

    $plan->refresh();
    $productReference = (string) $plan->getAttribute('iyzico_product_reference');
    $pricingReference = (string) $plan->getAttribute('iyzico_pricing_plan_reference');

    Artisan::call('subguard:sync-plans', [
        '--provider' => 'iyzico',
        '--remote' => true,
    ]);

    $plan->refresh();

    expect((string) $plan->getAttribute('iyzico_product_reference'))->toBe($productReference)
        ->and((string) $plan->getAttribute('iyzico_pricing_plan_reference'))->toBe($pricingReference);
});

function phaseEightCreateIyzicoRemotePlan(IyzicoSandboxRunContext $context, string $suffix, float $price): Plan
{
    return Plan::query()->create([
        'name' => sprintf('P8 %s %s', ucfirst($suffix), substr($context->runId(), -6)),
        'slug' => 'p8-'.$context->scopedValue($suffix),
        'price' => $price,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);
}
