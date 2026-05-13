<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comp_config_options')) {
            return;
        }

        Schema::create('comp_config_options', function (Blueprint $table) {
            $table->id();
            $table->string('option_label');
            $table->string('option_value');
            $table->unsignedBigInteger('comp_config_id');
            $table->timestamps();

            $table->index('comp_config_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_config_options');
    }
};
