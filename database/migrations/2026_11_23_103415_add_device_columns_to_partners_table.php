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
        Schema::table('partners', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('apple_id')->nullable()->unique()->after('google_id');
            $table->string('device_id')->nullable();
            $table->string('device_token')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('app_version', 20)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'apple_id', 'device_id', 'device_token', 'device_type', 'app_version']);
        });
    }
};
