<?php

use App\Enums\ClientStage;
use App\Enums\ClientStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_user_id')->index();
            $table->string('title');
            $table->string('status')->default(ClientStatus::Active->value)->index();
            $table->string('stage')->default(ClientStage::Intake->value)->index();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('telegram_user_id')
                ->references('telegram_user_id')
                ->on('telegram_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
