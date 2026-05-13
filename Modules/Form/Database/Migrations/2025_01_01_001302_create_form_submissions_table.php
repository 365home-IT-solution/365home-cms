<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('form_submissions')) {
            return;
        }

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_viewed')->default(false);
            $table->char('viewed_by', 36)->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedBigInteger('form_id');
            $table->timestamps();

            $table->index('form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
