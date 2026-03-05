<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\LaravelSubscriptionGuardCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessDunningCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessPlanChangesCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessRenewalsCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\SuspendOverdueCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\FeatureGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\ProviderEvents\ProviderEventDispatcherResolver;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Commands\ReconcileIyzicoSubscriptionsCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Commands\SyncPlansCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\IyzicoProviderEventDispatcher;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\PayTR\PaytrProviderEventDispatcher;
use SubscriptionGuard\LaravelSubscriptionGuard\Subscription\SubscriptionService;

class LaravelSubscriptionGuardServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-subscription-guard')
            ->hasConfigFile()
            ->hasCommands([
                LaravelSubscriptionGuardCommand::class,
                ProcessRenewalsCommand::class,
                ProcessDunningCommand::class,
                SuspendOverdueCommand::class,
                ProcessPlanChangesCommand::class,
                SyncPlansCommand::class,
                ReconcileIyzicoSubscriptionsCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->app->singleton(PaymentManager::class, static fn (): PaymentManager => new PaymentManager);
        $this->app->singleton(IyzicoProvider::class, static fn (): IyzicoProvider => new IyzicoProvider);
        $this->app->singleton(PaytrProvider::class, static fn (): PaytrProvider => new PaytrProvider);
        $this->app->singleton(IyzicoProviderEventDispatcher::class, static fn (): IyzicoProviderEventDispatcher => new IyzicoProviderEventDispatcher);
        $this->app->singleton(PaytrProviderEventDispatcher::class, static fn (): PaytrProviderEventDispatcher => new PaytrProviderEventDispatcher);
        $this->app->singleton(ProviderEventDispatcherResolver::class, fn (): ProviderEventDispatcherResolver => new ProviderEventDispatcherResolver($this->app));
        $this->app->singleton(LicenseManager::class, static fn (): LicenseManager => new LicenseManager);
        $this->app->singleton(SubscriptionService::class, fn (): SubscriptionService => new SubscriptionService(
            $this->app->make(PaymentManager::class),
            $this->app->make(ProviderEventDispatcherResolver::class),
        ));
        $this->app->singleton(FeatureGate::class, static fn (): FeatureGate => new FeatureGate);
        $this->app->singleton(LicenseManagerInterface::class, LicenseManager::class);
        $this->app->singleton(SubscriptionServiceInterface::class, SubscriptionService::class);
        $this->app->singleton(FeatureGateInterface::class, FeatureGate::class);

        $this->registerWebhookRoutes();
    }

    private function registerWebhookRoutes(): void
    {
        if (! config('subscription-guard.webhooks.auto_register_routes', true)) {
            return;
        }

        Route::middleware(config('subscription-guard.webhooks.middleware', ['api']))
            ->prefix(config('subscription-guard.webhooks.prefix', 'subguard/webhooks'))
            ->group(__DIR__.'/../routes/webhooks.php');
    }
}
