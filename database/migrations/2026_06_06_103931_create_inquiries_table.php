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
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20);
            $table->enum('trip_type', ['oneway', 'roundtrip', 'airport_pickup', 'airport_drop', 'sightseen', 'tour_package']);
            $table->string('pickup_location');
            $table->string('drop_location')->nullable();
            $table->date('pickup_date');
            $table->date('return_date')->nullable();
            $table->tinyInteger('passengers')->default(1);
            $table->enum('vehicle_type', ['sedan', 'suv', 'tempo_traveller', 'bus']);
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('pending');
            $table->timestamps();

            $table->index('trip_type');
            $table->index('status');
            $table->index('pickup_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
