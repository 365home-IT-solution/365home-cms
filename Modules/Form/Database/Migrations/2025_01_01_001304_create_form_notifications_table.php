<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_notifications')) {
            return;
        }

        Schema::create('form_notifications', function (Blueprint $table) {
            $table->id();
            $table->text('success_message')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('form_id');
            $table->timestamps();

            $table->index('form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_notifications');
    }
};
