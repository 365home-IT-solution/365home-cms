<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_section_opts')) {
            return;
        }

        Schema::create('theme_section_opts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_section_cfg_id');
            $table->string('option');
            $table->string('value');
            $table->timestamps();

            $table->index('theme_section_cfg_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_section_opts');
    }
};
