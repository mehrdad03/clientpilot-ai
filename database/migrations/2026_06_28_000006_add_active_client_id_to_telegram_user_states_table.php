<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('telegram_user_states', 'active_client_id')) {
            return;
        }

        Schema::table('telegram_user_states', function (Blueprint $table): void {
            $table->foreignId('active_client_id')
                ->nullable()
                ->after('payload')
                ->constrained('clients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('telegram_user_states', 'active_client_id')) {
            return;
        }

        Schema::table('telegram_user_states', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_client_id');
        });
    }
};
