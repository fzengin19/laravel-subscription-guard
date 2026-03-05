<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_activations', function (Blueprint $table): void {
            $table->index(
                ['license_id', 'domain', 'deactivated_at'],
                'license_activations_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('license_activations', function (Blueprint $table): void {
            $table->dropIndex('license_activations_lookup_idx');
        });
    }
};
