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
      Schema::create('salons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->unique(); // one salon per partner
            $table->string('business_name');
            $table->enum('business_type', ['unisex', 'men', 'women']);
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('owner_name');
            $table->string('mobile_number', 15)->unique();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('alternative_number', 15)->nullable();
            $table->timestamp('alternative_number_verified_at')->nullable();

            $table->string('pincode', 10);
            $table->string('country', 100);
            $table->string('state', 100);
            $table->string('city', 100);
            $table->string('locality', 150);
            $table->string('address_line');
            $table->string('landmark')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->tinyInteger('status')->default(1);
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salons');
    }
};
