<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_usages', function (Blueprint $table): void {
            $table->index(
                ['license_id', 'metric', 'period_start', 'period_end'],
                'license_usages_period_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('license_usages', function (Blueprint $table): void {
            $table->dropIndex('license_usages_period_lookup_idx');
        });
    }
};
