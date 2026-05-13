<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_field_values')) {
            return;
        }

        Schema::create('form_field_values', function (Blueprint $table) {
            $table->id();
            $table->text('value')->nullable();
            $table->unsignedBigInteger('form_submission_id');
            $table->unsignedBigInteger('form_field_id');
            $table->timestamps();

            $table->index('form_submission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_field_values');
    }
};
