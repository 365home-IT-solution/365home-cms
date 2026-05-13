<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_configurations')) {
            return;
        }

        Schema::create('payment_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->nullable();
            $table->string('api_key')->nullable();
            $table->string('checksum_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_configurations');
    }
};
