<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive only (no rewrites of existing data):
     *  - profiles keep the full validated upstream profile object, plus typed columns for fields worth querying
     *  - each account owns its upstream credential: rate limits and 401s are per key, so isolation is per account
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->unsignedInteger('medias_count')->nullable()->after('audios_count');
            $table->boolean('is_verified')->nullable()->after('medias_count');
            $table->decimal('subscribe_price', 8, 2)->nullable()->after('is_verified');
            $table->timestamp('join_date')->nullable()->after('subscribe_price');
            $table->json('upstream_payload')->nullable()->after('join_date');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->text('api_key')->nullable()->after('upstream'); // encrypted (Account cast)
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['medias_count', 'is_verified', 'subscribe_price', 'join_date', 'upstream_payload']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }
};
