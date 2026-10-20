<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history: one row per fetch attempt, never updated.
     * This is the evidence that a profile was (or wasn't) refreshed, independent of queue job status.
     */
    public function up(): void
    {
        Schema::create('refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            // The account that made this attempt (kept as-is when a profile moves accounts).
            // Denormalized so per-account metrics don't need a join; restrict so history isn't lost.
            $table->foreignId('account_id')->constrained()->restrictOnDelete();

            // Not unique: a replayed job (worker died before ack) legitimately repeats the same pair
            $table->uuid('job_uuid')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('handler', 16);

            $table->string('outcome', 32);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('response_format', 16)->nullable();
            $table->unsignedBigInteger('revision_before')->nullable();
            $table->unsignedBigInteger('revision_received')->nullable();
            $table->json('changes')->nullable();
            $table->string('error', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_id', 'created_at']);
            $table->index(['account_id', 'created_at']);
            $table->index('job_uuid');
            $table->index('created_at'); // retention pruning
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_attempts');
    }
};
