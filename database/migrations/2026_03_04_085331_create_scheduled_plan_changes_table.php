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
        Schema::create('scheduled_plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('from_plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('to_plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('change_type')->default('switch');
            $table->timestamp('scheduled_at');
            $table->string('proration_type')->default('none');
            $table->decimal('proration_credit', 10, 2)->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scheduled_at', 'status']);
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->foreign('scheduled_change_id')
                ->references('id')
                ->on('scheduled_plan_changes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['scheduled_change_id']);
        });

        Schema::dropIfExists('scheduled_plan_changes');
    }
};
