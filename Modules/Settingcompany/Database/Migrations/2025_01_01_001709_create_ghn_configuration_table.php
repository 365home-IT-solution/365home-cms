<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghn_configuration', function (Blueprint $table) {
            $table->id();
            $table->string('api_token')->nullable();
            $table->string('client_id')->nullable();
            $table->string('shop_id')->nullable();
            $table->integer('payment_type_id')->nullable();
            $table->string('required_note')->nullable();
            $table->string('return_phone')->nullable();
            $table->string('return_address')->nullable();
            $table->string('return_district_id')->nullable();
            $table->string('return_ward_code')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_phone')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_ward_name')->nullable();
            $table->string('from_district_name')->nullable();
            $table->string('from_province_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghn_configuration');
    }
};
