<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comp_page_values')) {
            return;
        }

        Schema::create('comp_page_values', function (Blueprint $table) {
            $table->id();
            $table->longText('value');
            $table->string('type')->default('string');
            $table->unsignedBigInteger('comp_page_id');
            $table->unsignedBigInteger('comp_config_id');
            $table->timestamps();

            $table->index('comp_page_id');
            $table->index('comp_config_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_page_values');
    }
};
