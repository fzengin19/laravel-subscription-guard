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
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('key')->unique();
            $table->string('status')->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->json('feature_overrides')->nullable();
            $table->json('limit_overrides')->nullable();
            $table->unsignedInteger('max_activations')->default(1);
            $table->unsignedInteger('current_activations')->default(0);
            $table->string('domain')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
