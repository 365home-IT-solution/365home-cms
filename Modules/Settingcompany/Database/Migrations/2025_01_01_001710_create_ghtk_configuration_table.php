<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghtk_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('api_token')->nullable();
            $table->string('partner_code')->nullable();
            $table->string('pick_name')->nullable();
            $table->string('pick_address')->nullable();
            $table->string('pick_province')->nullable();
            $table->string('pick_district')->nullable();
            $table->string('pick_ward')->nullable();
            $table->string('pick_tel')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghtk_configuration');
    }
};
