<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->bigInteger('telegram_user_id')->index();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->text('summary')->nullable();
            $table->text('current_context')->nullable();
            $table->text('what_client_wants')->nullable();
            $table->text('what_mehrdad_promised')->nullable();
            $table->text('pricing_discussed')->nullable();
            $table->text('deadline_discussed')->nullable();
            $table->text('access_needed')->nullable();
            $table->text('open_questions')->nullable();
            $table->text('risk_notes')->nullable();
            $table->text('next_best_move')->nullable();
            $table->foreignId('last_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_summaries');
    }
};
