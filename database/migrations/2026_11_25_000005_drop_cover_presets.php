<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('salon_services', 'cover_preset_id')) {
            try {
                Schema::table('salon_services', function (Blueprint $table) {
                    $table->dropForeign(['cover_preset_id']);
                });
            } catch (\Throwable $e) {
                // no foreign key (MyISAM): nothing to drop
            }

            Schema::table('salon_services', function (Blueprint $table) {
                $table->dropColumn('cover_preset_id');
            });
        }

        Schema::dropIfExists('service_cover_presets');
    }

    public function down(): void
    {
        // intentionally empty: cover presets are not used any more
    }
};
