<?php

namespace SubscriptionGuard\LaravelSubscriptionGuard;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\LaravelSubscriptionGuardCommand;

class LaravelSubscriptionGuardServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-subscription-guard')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_subscription_guard_table')
            ->hasCommand(LaravelSubscriptionGuardCommand::class);
    }
}
