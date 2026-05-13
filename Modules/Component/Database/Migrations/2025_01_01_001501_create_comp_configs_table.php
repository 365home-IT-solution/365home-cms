<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comp_configs')) {
            return;
        }

        Schema::create('comp_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('label')->nullable();
            $table->string('type')->default('string');
            $table->string('type_field')->default('text');
            $table->string('field_set')->default('group');
            $table->boolean('has_options')->default(false);
            $table->unsignedBigInteger('component_id');
            $table->timestamps();

            $table->index('component_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_configs');
    }
};
