<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_units', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_units');
    }
};
