<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'client_type',
            'personality_type',
            'main_need',
            'best_strategy',
            'risk_level',
            'client_summary',
        ];

        $missingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! Schema::hasColumn('clients', $column)
        ));

        if ($missingColumns === []) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($missingColumns): void {
            foreach ($missingColumns as $column) {
                $table->text($column)->nullable();
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'client_type',
            'personality_type',
            'main_need',
            'best_strategy',
            'risk_level',
            'client_summary',
        ];

        $existingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('clients', $column)
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
