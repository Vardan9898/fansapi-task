<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per profile holding its last VALID state, updated in place.
     * Data columns are nullable on purpose: null means "never fetched", never a fake 0.
     */
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            // Restrict: an account with profiles can't be deleted; move its profiles first
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('username', 100);

            // Public profile data (written only from a validated response)
            $table->unsignedBigInteger('upstream_id')->nullable();
            $table->string('name')->nullable();
            $table->text('about')->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('header_url', 1024)->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedInteger('posts_count')->nullable();
            $table->unsignedInteger('photos_count')->nullable();
            $table->unsignedInteger('videos_count')->nullable();
            $table->unsignedInteger('audios_count')->nullable();

            // Upstream version of the stored data; writes only move it forward
            $table->unsignedBigInteger('revision')->nullable();

            // Refresh health: success is tracked separately from "a job ran"
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_outcome', 32)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();

            $table->unique(['account_id', 'username']);
            $table->index('last_success_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
