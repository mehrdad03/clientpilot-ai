<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_feedbacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_suggestion_id')->constrained('bot_suggestions')->cascadeOnDelete();
            $table->foreignId('replacement_bot_suggestion_id')->nullable()->constrained('bot_suggestions')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->bigInteger('telegram_user_id')->index();
            $table->text('feedback_text');
            $table->string('ai_decision')->nullable()->index();
            $table->text('ai_reason')->nullable();
            $table->string('result_action')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feedbacks');
    }
};
