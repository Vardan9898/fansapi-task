<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account is the unit that shares upstream capacity (rate limits, concurrency).
     * Isolation and cooldowns are keyed by account, so one busy account can't starve others.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Blocked accounts are kept (never deleted) so their profiles can be moved without data loss
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
