<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained('conversation_messages')->cascadeOnDelete();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->bigInteger('telegram_user_id')->index();
            $table->text('client_read');
            $table->text('best_move');
            $table->string('risk_level')->nullable()->index();
            $table->text('risk_reason')->nullable();
            $table->string('detected_intent')->nullable()->index();
            $table->string('next_stage')->nullable()->index();
            $table->foreignId('selected_option_id')->nullable()->index();
            $table->timestamp('selected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_suggestions');
    }
};
