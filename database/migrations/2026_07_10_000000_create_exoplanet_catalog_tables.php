<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exoplanets', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog_key', 64)->unique();
            $table->string('target', 120)->nullable()->index();
            $table->string('mission', 40)->nullable()->index();
            $table->string('candidate_id', 80)->nullable()->index();
            $table->double('period_days')->nullable();
            $table->double('epoch_bjd')->nullable();
            $table->double('duration_hours')->nullable();
            $table->boolean('use_llm')->default(true);
            $table->double('threshold')->nullable();
            $table->json('catalog_payload')->nullable();
            $table->json('prediction_payload')->nullable();
            $table->string('prediction_status', 32)->default('not_requested')->index();
            $table->timestamp('prediction_completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exoplanet_prediction_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exoplanet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('remote_job_id')->nullable()->index();
            $table->timestamp('queued_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('specialist_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'queued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exoplanet_prediction_jobs');
        Schema::dropIfExists('exoplanets');
    }
};
