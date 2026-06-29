<?php

use App\Enums\BotSuggestionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'status',
            'selected_option_id',
            'selected_text',
            'selected_at',
        ];

        $missingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => ! Schema::hasColumn('bot_suggestions', $column)
        ));

        if ($missingColumns === []) {
            return;
        }

        Schema::table('bot_suggestions', function (Blueprint $table) use ($missingColumns): void {
            foreach ($missingColumns as $column) {
                if ($column === 'status') {
                    $table->string('status')->default(BotSuggestionStatus::Generated->value)->index();
                }

                if ($column === 'selected_option_id') {
                    $table->foreignId('selected_option_id')->nullable()->index();
                }

                if ($column === 'selected_text') {
                    $table->text('selected_text')->nullable();
                }

                if ($column === 'selected_at') {
                    $table->timestamp('selected_at')->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'status',
            'selected_option_id',
            'selected_text',
            'selected_at',
        ];

        $existingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('bot_suggestions', $column)
        ));

        if ($existingColumns === []) {
            return;
        }

        Schema::table('bot_suggestions', function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
