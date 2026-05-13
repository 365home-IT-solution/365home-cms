<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fcm_tokens')) {
            return;
        }

        Schema::create('fcm_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 36);
            $table->text('token');
            $table->string('token_hash', 64);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fcm_tokens');
    }
};
