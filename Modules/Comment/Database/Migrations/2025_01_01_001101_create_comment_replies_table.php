<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comment_replies')) {
            return;
        }

        Schema::create('comment_replies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->text('text')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('pin')->default(false);
            $table->unsignedBigInteger('comment_id');
            $table->char('account_id', 36)->nullable();
            $table->timestamps();

            $table->index('comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_replies');
    }
};
