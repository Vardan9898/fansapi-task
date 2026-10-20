<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each account fetches through one upstream (its own credentials and rate limits).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('upstream', 16)->default('fake')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('upstream');
        });
    }
};
