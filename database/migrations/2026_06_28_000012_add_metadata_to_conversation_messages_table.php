<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('conversation_messages', 'metadata')) {
            return;
        }

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->json('metadata')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('conversation_messages', 'metadata')) {
            return;
        }

        Schema::table('conversation_messages', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
