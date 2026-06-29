<?php

use App\Enums\ConversationMessageType;
use App\Enums\ConversationSender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->bigInteger('telegram_user_id')->index();
            $table->string('sender')->default(ConversationSender::Client->value)->index();
            $table->string('message_type')->default(ConversationMessageType::Note->value)->index();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_messages');
    }
};
