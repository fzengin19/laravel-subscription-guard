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
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->morphs('discountable');
            $table->string('type')->default('percentage');
            $table->decimal('value', 10, 2)->default(0);
            $table->char('currency', 3)->default('TRY');
            $table->string('duration')->default('once');
            $table->unsignedInteger('duration_in_months')->nullable();
            $table->unsignedInteger('applied_cycles')->default(0);
            $table->decimal('applied_amount', 10, 2)->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
            $table->foreign('discount_id')->references('id')->on('discounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeign(['coupon_id']);
            $table->dropForeign(['discount_id']);
        });

        Schema::dropIfExists('discounts');
    }
};
