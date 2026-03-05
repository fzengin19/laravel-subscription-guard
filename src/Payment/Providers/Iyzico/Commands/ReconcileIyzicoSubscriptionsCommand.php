<?php

declare(strict_types=1);

namespace SubscriptionGuard\LaravelSubscriptionGuard\Payment\Providers\Iyzico\Commands;

use Illuminate\Console\Command;
use Iyzipay\Model\Subscription\SubscriptionDetails;
use Iyzipay\Options as IyzipayOptions;
use Iyzipay\Request\Subscription\SubscriptionDetailsRequest;
use SubscriptionGuard\LaravelSubscriptionGuard\Enums\SubscriptionStatus;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use Throwable;

final class ReconcileIyzicoSubscriptionsCommand extends Command
{
    protected $signature = 'subguard:reconcile-iyzico-subscriptions {--dry-run : Show reconciliation actions only} {--remote : Force remote iyzico status pull}';

    protected $description = 'Reconcile local iyzico subscriptions against provider-managed assumptions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forceRemote = (bool) $this->option('remote');
        $updated = 0;
        $checked = 0;
        $remoteFailures = 0;

        $remoteEnabled = $this->remoteEnabled();

        if ($forceRemote && ! $remoteEnabled) {
            $this->warn('Remote reconcile requested but iyzico credentials/mock settings do not allow live API calls. Falling back to local metadata reconcile.');
        }

        $subscriptions = Subscription::query()
            ->where('provider', 'iyzico')
            ->whereNotNull('provider_subscription_id')
            ->get();

        foreach ($subscriptions as $subscription) {
            $checked++;
            $status = (string) $subscription->getAttribute('status');
            $metadata = $subscription->getAttribute('metadata');

            $remoteStatus = null;

            if ($remoteEnabled) {
                try {
                    $remoteStatus = $this->fetchRemoteStatus((string) $subscription->getAttribute('provider_subscription_id'));
                } catch (Throwable $exception) {
                    $remoteFailures++;
                    $this->warn(sprintf('Remote status pull failed for subscription [%s]: %s', (string) $subscription->getKey(), $exception->getMessage()));
                }
            }

            if ($remoteStatus === null && is_array($metadata)) {
                $remoteStatus = $this->normalizeRemoteStatus($metadata['iyzico_remote_status'] ?? null);
            }

            if ($remoteStatus !== null && $remoteStatus !== $status) {
                if ($dryRun) {
                    $updated++;

                    continue;
                }

                $subscription->setAttribute('status', $remoteStatus);

                if ($remoteStatus === SubscriptionStatus::Cancelled->value && $subscription->getAttribute('cancelled_at') === null) {
                    $subscription->setAttribute('cancelled_at', now());
                }

                $subscription->save();
                $updated++;

                continue;
            }

            if ($status !== SubscriptionStatus::Pending->value || $remoteEnabled) {
                continue;
            }

            if ($dryRun) {
                $updated++;

                continue;
            }

            $subscription->setAttribute('status', SubscriptionStatus::Active->value);
            $subscription->save();
            $updated++;
        }

        $this->info(sprintf('Reconciliation complete. checked=%d updated=%d remote_failures=%d', $checked, $updated, $remoteFailures));

        return self::SUCCESS;
    }

    private function normalizeRemoteStatus(mixed $status): ?string
    {
        if (! is_scalar($status)) {
            return null;
        }

        $normalized = strtolower(trim((string) $status));

        return SubscriptionStatus::normalize($normalized)?->value;
    }

    private function fetchRemoteStatus(string $subscriptionReference): ?string
    {
        if ($subscriptionReference === '') {
            return null;
        }

        $request = new SubscriptionDetailsRequest;
        $request->setSubscriptionReferenceCode($subscriptionReference);

        $response = SubscriptionDetails::retrieve($request, $this->iyzicoOptions());

        if (! method_exists($response, 'getStatus') || strtolower((string) $response->getStatus()) !== 'success') {
            $error = method_exists($response, 'getErrorMessage') ? trim((string) $response->getErrorMessage()) : '';
            throw new \RuntimeException($error !== '' ? $error : 'Remote subscription status request failed.');
        }

        return $this->normalizeRemoteStatus(method_exists($response, 'getSubscriptionStatus') ? $response->getSubscriptionStatus() : null);
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
}
