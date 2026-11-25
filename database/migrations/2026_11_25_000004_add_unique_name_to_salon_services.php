<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a service name can exist only once per salon
        Schema::table('salon_services', function (Blueprint $table) {
            $table->unique(['salon_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('salon_services', function (Blueprint $table) {
            $table->dropUnique(['salon_id', 'name']);
        });
    }
};
