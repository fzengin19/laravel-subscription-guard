<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\MeteredBillingProcessor;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\SeatManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\CheckLicenseCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\GenerateLicenseCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\LaravelSubscriptionGuardCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessDunningCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessMeteredBillingCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessPlanChangesCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\ProcessRenewalsCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Commands\SuspendOverdueCommand;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\FeatureGateInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\LicenseManagerInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Contracts\SubscriptionServiceInterface;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentCompleted;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\PaymentFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCancelled;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionCreated;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewalFailed;
use SubscriptionGuard\LaravelSubscriptionGuard\Events\SubscriptionRenewed;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\FeatureGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\FeatureManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Features\ScheduleGate;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Controllers\LicenseValidationController;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Middleware\LicenseFeatureMiddleware;
use SubscriptionGuard\LaravelSubscriptionGuard\Http\Middleware\LicenseLimitMiddleware;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseManager;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseRevocationStore;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\LicenseSignature;
use SubscriptionGuard\LaravelSubscriptionGuard\Licensing\Listeners\LicenseLifecycleListener;
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
                ProcessMeteredBillingCommand::class,
                ProcessPlanChangesCommand::class,
                SyncPlansCommand::class,
                ReconcileIyzicoSubscriptionsCommand::class,
                GenerateLicenseCommand::class,
                CheckLicenseCommand::class,
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
        $this->app->singleton(LicenseRevocationStore::class, static fn (): LicenseRevocationStore => new LicenseRevocationStore);
        $this->app->singleton(LicenseSignature::class, static fn (): LicenseSignature => new LicenseSignature);
        $this->app->singleton(LicenseManager::class, fn (): LicenseManager => new LicenseManager(
            $this->app->make(LicenseSignature::class),
            $this->app->make(LicenseRevocationStore::class),
        ));
        $this->app->singleton(SubscriptionService::class, fn (): SubscriptionService => new SubscriptionService(
            $this->app->make(PaymentManager::class),
            $this->app->make(ProviderEventDispatcherResolver::class),
        ));
        $this->app->singleton(FeatureGate::class, fn (): FeatureGate => new FeatureGate(
            $this->app->make(LicenseManagerInterface::class)
        ));
        $this->app->singleton(ScheduleGate::class, static fn (): ScheduleGate => new ScheduleGate);
        $this->app->singleton(FeatureManager::class, fn (): FeatureManager => new FeatureManager(
            $this->app->make(FeatureGateInterface::class),
            $this->app->make(ScheduleGate::class),
        ));
        $this->app->singleton(SeatManager::class, static fn (): SeatManager => new SeatManager);
        $this->app->singleton(MeteredBillingProcessor::class, static fn (): MeteredBillingProcessor => new MeteredBillingProcessor);
        $this->app->singleton(LicenseManagerInterface::class, LicenseManager::class);
        $this->app->singleton(SubscriptionServiceInterface::class, SubscriptionService::class);
        $this->app->singleton(FeatureGateInterface::class, FeatureGate::class);

        $licenseValidationRateKey = (string) config('subscription-guard.license.rate_limit.key', 'license-validation');
        $licenseValidationMaxAttempts = max(1, (int) config('subscription-guard.license.rate_limit.max_attempts', 60));
        $licenseValidationDecayMinutes = max(1, (int) config('subscription-guard.license.rate_limit.decay_minutes', 1));

        RateLimiter::for($licenseValidationRateKey, static function (Request $request) use ($licenseValidationMaxAttempts, $licenseValidationDecayMinutes) {
            $licenseKey = trim((string) $request->input('license_key', ''));
            $identifier = $licenseKey !== ''
                ? 'license:'.hash('sha256', $licenseKey)
                : 'ip:'.(string) ($request->ip() ?? '127.0.0.1');

            return Limit::perMinutes($licenseValidationDecayMinutes, $licenseValidationMaxAttempts)
                ->by($identifier);
        });

        $router = $this->app->make('router');
        $router->aliasMiddleware('subguard.feature', LicenseFeatureMiddleware::class);
        $router->aliasMiddleware('subguard.limit', LicenseLimitMiddleware::class);

        Blade::if('subguardfeature', static function (string $licenseKey, string $feature): bool {
            return app(FeatureGateInterface::class)->can($licenseKey, $feature);
        });

        Blade::if('subguardlimit', static function (string $licenseKey, string $limit, int|float $amount = 1): bool {
            return app(FeatureGateInterface::class)->limit($licenseKey, $limit) >= $amount;
        });

        $this->registerWebhookRoutes();
        $this->registerLicenseRoutes($licenseValidationRateKey);
        $this->registerLicenseLifecycleListeners();
    }

    private function registerLicenseLifecycleListeners(): void
    {
        Event::listen(SubscriptionCreated::class, [LicenseLifecycleListener::class, 'onSubscriptionCreated']);
        Event::listen(PaymentCompleted::class, [LicenseLifecycleListener::class, 'onPaymentCompleted']);
        Event::listen(PaymentFailed::class, [LicenseLifecycleListener::class, 'onPaymentFailed']);
        Event::listen(SubscriptionRenewed::class, [LicenseLifecycleListener::class, 'onSubscriptionRenewed']);
        Event::listen(SubscriptionRenewalFailed::class, [LicenseLifecycleListener::class, 'onSubscriptionRenewalFailed']);
        Event::listen(SubscriptionCancelled::class, [LicenseLifecycleListener::class, 'onSubscriptionCancelled']);
    }

    private function registerLicenseRoutes(string $licenseValidationRateKey): void
    {
        if (! config('subscription-guard.license.auto_register_validation_route', true)) {
            return;
        }

        $path = trim((string) config('subscription-guard.license.validation_path', 'subguard/licenses/validate'), '/');

        Route::middleware(['api', 'throttle:'.$licenseValidationRateKey])
            ->post('/'.$path, LicenseValidationController::class)
            ->name('subscription-guard.license.validate');
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
