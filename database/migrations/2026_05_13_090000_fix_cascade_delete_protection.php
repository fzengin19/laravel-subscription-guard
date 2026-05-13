<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'deleted_at')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }

        $this->swapCascadeToRestrict('subscriptions', 'plan_id', 'plans');
        $this->swapCascadeToRestrict('licenses', 'plan_id', 'plans');
        $this->swapCascadeToRestrict('licenses', 'user_id', 'users');
        $this->swapCascadeToRestrict('subscription_items', 'plan_id', 'plans');
    }

    public function down(): void
    {
        $this->swapRestrictToCascade('subscriptions', 'plan_id', 'plans');
        $this->swapRestrictToCascade('licenses', 'plan_id', 'plans');
        $this->swapRestrictToCascade('licenses', 'user_id', 'users');
        $this->swapRestrictToCascade('subscription_items', 'plan_id', 'plans');

        if (Schema::hasColumn('plans', 'deleted_at')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }

    private function swapCascadeToRestrict(string $childTable, string $column, string $parentTable): void
    {
        try {
            Schema::table($childTable, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (QueryException) {
            // FK already missing — fall through to recreate.
        }

        Schema::table($childTable, function (Blueprint $table) use ($column, $parentTable): void {
            $table->foreign($column)
                ->references('id')
                ->on($parentTable)
                ->restrictOnDelete();
        });
    }

    private function swapRestrictToCascade(string $childTable, string $column, string $parentTable): void
    {
        try {
            Schema::table($childTable, function (Blueprint $table) use ($column): void {
                $table->dropForeign([$column]);
            });
        } catch (QueryException) {
            // FK already missing.
        }

        Schema::table($childTable, function (Blueprint $table) use ($column, $parentTable): void {
            $table->foreign($column)
                ->references('id')
                ->on($parentTable)
                ->cascadeOnDelete();
        });
    }
};
