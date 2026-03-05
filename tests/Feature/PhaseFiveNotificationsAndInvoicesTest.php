<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use SubscriptionGuard\LaravelSubscriptionGuard\Billing\Invoices\InvoicePdfRenderer;
use SubscriptionGuard\LaravelSubscriptionGuard\Jobs\DispatchBillingNotificationsJob;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Invoice;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Plan;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Subscription;
use SubscriptionGuard\LaravelSubscriptionGuard\Models\Transaction;
use SubscriptionGuard\LaravelSubscriptionGuard\Notifications\InvoicePaidNotification;
use SubscriptionGuard\LaravelSubscriptionGuard\Notifications\SubscriptionCancelledNotification;

it('dispatches invoice paid notification and creates invoice on payment.completed', function (): void {
    Notification::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Notification User',
        'email' => 'phase5-notify@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Notification Plan',
        'slug' => 'phase5-notification-plan',
        'price' => 120,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => PhaseFiveNotifiableUser::class,
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_sub_'.bin2hex(random_bytes(4)),
        'status' => 'active',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 120,
        'currency' => 'TRY',
        'next_billing_date' => now()->addMonth(),
    ]);

    $transaction = Transaction::query()->create([
        'subscription_id' => $subscription->getKey(),
        'payable_type' => PhaseFiveNotifiableUser::class,
        'payable_id' => $userId,
        'provider' => 'paytr',
        'provider_transaction_id' => 'phase5_txn_'.bin2hex(random_bytes(4)),
        'type' => 'renewal',
        'status' => 'processed',
        'amount' => 120,
        'currency' => 'TRY',
        'processed_at' => now(),
        'idempotency_key' => 'phase5:invoice:'.bin2hex(random_bytes(4)),
    ]);

    (new DispatchBillingNotificationsJob('payment.completed', [
        'transaction_id' => $transaction->getKey(),
        'subscription_id' => $subscription->getKey(),
        'provider' => 'paytr',
        'amount' => 120,
    ]))->handle(app(InvoicePdfRenderer::class));

    $user = PhaseFiveNotifiableUser::query()->findOrFail($userId);

    Notification::assertSentTo($user, InvoicePaidNotification::class);

    $invoice = Invoice::query()->where('transaction_id', $transaction->getKey())->first();

    expect($invoice)->not->toBeNull();
    expect((string) ($invoice?->getAttribute('pdf_path') ?? ''))->not->toBe('');
    expect(Storage::disk('local')->exists((string) $invoice?->getAttribute('pdf_path')))->toBeTrue();
});

it('dispatches cancellation notification for subscription.cancelled event', function (): void {
    Notification::fake();

    $userId = (int) DB::table('users')->insertGetId([
        'name' => 'Phase5 Cancel User',
        'email' => 'phase5-cancel@example.test',
        'password' => 'secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $plan = Plan::query()->create([
        'name' => 'Phase5 Cancel Plan',
        'slug' => 'phase5-cancel-plan',
        'price' => 50,
        'currency' => 'TRY',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'subscribable_type' => PhaseFiveNotifiableUser::class,
        'subscribable_id' => $userId,
        'plan_id' => $plan->getKey(),
        'provider' => 'paytr',
        'provider_subscription_id' => 'phase5_cancel_sub_'.bin2hex(random_bytes(4)),
        'status' => 'cancelled',
        'billing_period' => 'monthly',
        'billing_interval' => 1,
        'amount' => 50,
        'currency' => 'TRY',
        'cancelled_at' => now(),
        'next_billing_date' => null,
    ]);

    (new DispatchBillingNotificationsJob('subscription.cancelled', [
        'subscription_id' => $subscription->getKey(),
        'provider' => 'paytr',
    ]))->handle(app(InvoicePdfRenderer::class));

    $user = PhaseFiveNotifiableUser::query()->findOrFail($userId);

    Notification::assertSentTo($user, SubscriptionCancelledNotification::class);
});

final class PhaseFiveNotifiableUser extends Model
{
    use Notifiable;

    protected $table = 'users';

    protected $guarded = [];
}
