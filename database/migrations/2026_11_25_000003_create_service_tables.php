<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Master data (shared by every salon, used for dropdowns) ----
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Flat list for the "Service Name" dropdown. NOT mapped to a category.
        Schema::create('service_names', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- Per salon (branch) ----
        Schema::create('salon_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained('salons')->cascadeOnDelete();
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('name', 60);                   // master name, or the custom "Others" name
            $table->timestamps();

            $table->unique(['salon_id', 'name']);
        });

        Schema::create('salon_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained('salons')->cascadeOnDelete();
            $table->foreignId('salon_category_id')->constrained('salon_categories')->cascadeOnDelete();
            $table->foreignId('service_name_id')->nullable()->constrained('service_names')->nullOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('for_whom', 10);               // men | women | kids | everyone
            $table->decimal('price', 10, 2);
            $table->string('cover_image')->nullable();    // uploaded by the owner (path on "public" disk)
            $table->boolean('is_active')->default(true);  // false = hidden from customers
            $table->timestamps();

            $table->index(['salon_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salon_services');
        Schema::dropIfExists('salon_categories');
        Schema::dropIfExists('service_names');
        Schema::dropIfExists('service_categories');
    }
};
