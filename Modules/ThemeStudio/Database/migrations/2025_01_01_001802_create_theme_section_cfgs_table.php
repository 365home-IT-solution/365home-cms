<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_section_cfgs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_section_id');
            $table->string('key');
            $table->string('label');
            $table->longText('value')->nullable();
            $table->longText('default_value')->nullable();
            $table->string('data_type')->nullable();
            $table->string('field_type');
            $table->string('help_text')->nullable();
            $table->string('group_name')->nullable();
            $table->boolean('has_option')->default(false);
            $table->decimal('min_value', 10, 0)->nullable();
            $table->decimal('max_value', 10, 0)->nullable();
            $table->string('suffix_value')->nullable();
            $table->timestamps();

            $table->index('theme_section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_section_cfgs');
    }
};
