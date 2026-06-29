<?php

use App\Enums\AiRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            $table->string('model')->nullable()->index();
            $table->string('prompt_key')->nullable()->index();
            $table->string('prompt_version')->nullable();
            $table->string('status')->default(AiRequestStatus::Pending->value)->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('queue_name')->nullable()->index();
            $table->string('job_class')->nullable();
            $table->string('job_uuid')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
