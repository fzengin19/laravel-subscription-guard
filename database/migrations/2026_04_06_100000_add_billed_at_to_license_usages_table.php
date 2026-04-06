<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_usages', function (Blueprint $table) {
            $table->timestamp('billed_at')->nullable()->after('metadata');
            $table->index(['license_id', 'metric', 'billed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('license_usages', function (Blueprint $table) {
            $table->dropIndex(['license_id', 'metric', 'billed_at']);
            $table->dropColumn('billed_at');
        });
    }
};
