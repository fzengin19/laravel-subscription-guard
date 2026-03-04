<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->morphs('payable');
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->string('provider');
            $table->string('provider_transaction_id')->nullable();
            $table->string('provider_refund_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('type')->default('payment');
            $table->string('status')->default('pending');
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            $table->char('provider_currency', 3)->nullable();
            $table->decimal('exchange_rate', 12, 6)->nullable();
            $table->decimal('fee', 10, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->string('card_brand')->nullable();
            $table->unsignedTinyInteger('installment')->default(1);
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_retry_at')->nullable();
            $table->foreignId('coupon_id')->nullable();
            $table->foreignId('discount_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'provider_transaction_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
