<?php

declare(strict_types=1);

use SubscriptionGuard\LaravelSubscriptionGuard\Tests\Support\Live\IyzicoSandboxGate;

it('requires a public https tunnel for real webhook and callback roundtrip validation', function (): void {
    IyzicoSandboxGate::skipUnlessOperatorAssisted($this);

    $this->markTestSkipped('Requires manual browser completion');
});

it('reserves mdStatus edge cards for operator assisted 3ds callback verification', function (): void {
    IyzicoSandboxGate::skipUnlessOperatorAssisted($this);

    $this->markTestSkipped('Requires sandbox webhook delivery');
});
