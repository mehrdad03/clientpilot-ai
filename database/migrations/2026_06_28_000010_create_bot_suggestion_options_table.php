<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_suggestion_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bot_suggestion_id')->constrained('bot_suggestions')->cascadeOnDelete();
            $table->unsignedTinyInteger('option_number');
            $table->string('label');
            $table->text('body');
            $table->timestamps();

            $table->unique(['bot_suggestion_id', 'option_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_suggestion_options');
    }
};
