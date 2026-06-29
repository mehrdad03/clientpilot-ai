<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_user_states', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('state')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('telegram_user_id')
                ->references('telegram_user_id')
                ->on('telegram_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_user_states');
    }
};
