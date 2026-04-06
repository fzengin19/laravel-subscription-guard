<?php

declare(strict_types=1);

it('defaults iyzico mock mode to false for production safety', function () {
    $mock = config('subscription-guard.providers.drivers.iyzico.mock');
    expect($mock)->toBeFalse('iyzico mock should default to false for production safety');
});

it('defaults paytr mock mode to false for production safety', function () {
    $mock = config('subscription-guard.providers.drivers.paytr.mock');
    expect($mock)->toBeFalse('paytr mock should default to false for production safety');
});
