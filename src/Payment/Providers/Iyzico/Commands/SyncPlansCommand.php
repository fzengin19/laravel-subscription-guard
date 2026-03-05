<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Commands;

use Illuminate\Console\Command;
use Iyzipay\Model\Subscription\SubscriptionPricingPlan;
use Iyzipay\Model\Subscription\SubscriptionProduct;
use Iyzipay\Options as IyzipayOptions;
use Iyzipay\Request\Subscription\SubscriptionCreatePricingPlanRequest;
use Iyzipay\Request\Subscription\SubscriptionCreateProductRequest;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Payment\PaymentManager;
use Throwable;

final class SyncPlansCommand extends Command
{
    protected $signature = 'subguard:sync-plans {--provider=iyzico : Provider to sync plans for} {--dry-run : Only show planned changes} {--force : Refresh existing references} {--remote : Force remote iyzico API synchronization}';

    protected $description = 'Sync local plans to provider product and pricing plan references';

    public function handle(PaymentManager $paymentManager): int
    {
        $provider = (string) $this->option('provider');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $forceRemote = (bool) $this->option('remote');

        if ($provider !== 'iyzico') {
            $this->error('Currently only iyzico plan sync is supported.');

            return self::FAILURE;
        }

        if (! $paymentManager->hasProvider($provider)) {
            $this->error(sprintf('Provider [%s] is not configured.', $provider));

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $conflicts = 0;

        $remoteEnabled = $this->remoteEnabled();

        if ($forceRemote && ! $remoteEnabled) {
            $this->warn('Remote sync requested but iyzico credentials/mock settings do not allow live API calls. Falling back to local sync.');
        }

        $plans = Plan::query()->where('is_active', true)->get();

        foreach ($plans as $plan) {
            $slug = trim((string) $plan->getAttribute('slug'));

            if ($slug === '') {
                $conflicts++;
                $this->warn(sprintf('Plan [%s] skipped: missing canonical slug.', (string) $plan->getKey()));

                continue;
            }

            $productReference = (string) $plan->getAttribute('iyzico_product_reference');
            $pricingReference = (string) $plan->getAttribute('iyzico_pricing_plan_reference');
            $hasReferences = $productReference !== '' && $pricingReference !== '';

            if ($hasReferences && ! $force) {
                $skipped++;

                continue;
            }

            $newProductReference = 'iyz-prod-'.$slug;
            $newPricingReference = 'iyz-price-'.$slug;

            if ($remoteEnabled) {
                try {
                    [$newProductReference, $newPricingReference] = $this->syncRemotePlan($plan, $productReference, $pricingReference, $force, $dryRun);
                } catch (Throwable $exception) {
                    $conflicts++;
                    $this->warn(sprintf('Plan [%s] remote sync failed: %s', (string) $plan->getKey(), $exception->getMessage()));

                    continue;
                }
            }

            if (! $dryRun) {
                $plan->setAttribute('iyzico_product_reference', $newProductReference);
                $plan->setAttribute('iyzico_pricing_plan_reference', $newPricingReference);
                $plan->save();
            }

            if ($hasReferences) {
                $updated++;
            } else {
                $created++;
            }
        }

        $this->info(sprintf('Sync complete. created=%d updated=%d skipped=%d conflicts=%d', $created, $updated, $skipped, $conflicts));

        if ($conflicts > 0) {
            return 1;
        }

        return self::SUCCESS;
    }

    private function syncRemotePlan(Plan $plan, string $existingProductReference, string $existingPricingReference, bool $force, bool $dryRun): array
    {
        $productReference = $existingProductReference;
        $pricingReference = $existingPricingReference;

        if ($productReference === '' || $force) {
            if ($dryRun) {
                $productReference = 'iyz-prod-'.trim((string) $plan->getAttribute('slug'));
            } else {
                $request = new SubscriptionCreateProductRequest;
                $request->setConversationId((string) uniqid('iyz_product_', true));
                $request->setName((string) $plan->getAttribute('name'));
                $request->setDescription((string) ($plan->getAttribute('description') ?? $plan->getAttribute('name')));

                $response = SubscriptionProduct::create($request, $this->iyzicoOptions());

                if (! $this->isSuccessful($response)) {
                    throw new \RuntimeException($this->responseError($response, 'Unable to create remote iyzico product.'));
                }

                $productReference = (string) $response->getReferenceCode();
            }
        }

        if ($pricingReference === '' || $force) {
            if ($dryRun) {
                $pricingReference = 'iyz-price-'.trim((string) $plan->getAttribute('slug'));
            } else {
                $request = new SubscriptionCreatePricingPlanRequest;
                $request->setConversationId((string) uniqid('iyz_pricing_', true));
                $request->setProductReferenceCode($productReference);
                $request->setName((string) $plan->getAttribute('name'));
                $request->setPrice($this->money($plan->getAttribute('price')));
                $request->setCurrencyCode((string) $plan->getAttribute('currency'));
                $request->setPaymentInterval($this->mapPaymentInterval((string) $plan->getAttribute('billing_period')));
                $request->setPaymentIntervalCount((int) $plan->getAttribute('billing_interval'));
                $request->setTrialPeriodDays((int) ($plan->getAttribute('trial_days') ?? 0));
                $request->setPlanPaymentType('RECURRING');
                $request->setRecurrenceCount(0);

                $response = SubscriptionPricingPlan::create($request, $this->iyzicoOptions());

                if (! $this->isSuccessful($response)) {
                    throw new \RuntimeException($this->responseError($response, 'Unable to create remote iyzico pricing plan.'));
                }

                $pricingReference = (string) $response->getReferenceCode();
            }
        }

        return [$productReference, $pricingReference];
    }

    private function remoteEnabled(): bool
    {
        $config = $this->config();

        if ((bool) ($config['mock'] ?? true)) {
            return false;
        }

        return $this->missingCredentials() === [];
    }

    private function missingCredentials(): array
    {
        $config = $this->config();
        $missing = [];

        foreach (['api_key', 'secret_key', 'base_url'] as $key) {
            $value = trim((string) ($config[$key] ?? ''));

            if ($value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    private function iyzicoOptions(): IyzipayOptions
    {
        $config = $this->config();
        $options = new IyzipayOptions;
        $options->setApiKey((string) ($config['api_key'] ?? ''));
        $options->setSecretKey((string) ($config['secret_key'] ?? ''));
        $options->setBaseUrl((string) ($config['base_url'] ?? 'https://sandbox-api.iyzipay.com'));

        return $options;
    }

    private function config(): array
    {
        $config = config('subscription-guard.providers.drivers.iyzico', []);

        return is_array($config) ? $config : [];
    }

    private function isSuccessful(object $response): bool
    {
        if (! method_exists($response, 'getStatus')) {
            return false;
        }

        return strtolower((string) $response->getStatus()) === 'success';
    }

    private function responseError(object $response, string $fallback): string
    {
        if (method_exists($response, 'getErrorMessage')) {
            $message = trim((string) $response->getErrorMessage());

            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }

    private function mapPaymentInterval(string $period): string
    {
        return match (strtolower($period)) {
            'day', 'daily' => 'DAILY',
            'week', 'weekly' => 'WEEKLY',
            'year', 'yearly', 'annually' => 'YEARLY',
            default => 'MONTHLY',
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
