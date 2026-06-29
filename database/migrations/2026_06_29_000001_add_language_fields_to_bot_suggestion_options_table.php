<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $missingColumns = array_filter([
            'type',
            'native_meaning',
        ], static fn (string $column): bool => ! Schema::hasColumn('bot_suggestion_options', $column));

        if ($missingColumns === []) {
            return;
        }

        Schema::table('bot_suggestion_options', function (Blueprint $table) use ($missingColumns): void {
            if (in_array('type', $missingColumns, true)) {
                $table->string('type', 32)->nullable()->after('label');
            }

            if (in_array('native_meaning', $missingColumns, true)) {
                $table->text('native_meaning')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        $existingColumns = array_filter([
            'native_meaning',
            'type',
        ], static fn (string $column): bool => Schema::hasColumn('bot_suggestion_options', $column));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('bot_suggestion_options', function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
