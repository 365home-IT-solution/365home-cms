<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('customer_id', 36);
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->string('status', 20)->default('open'); // open, closed
            $table->string('last_message_preview', 200)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedSmallInteger('admin_unread')->default(0);
            $table->unsignedSmallInteger('customer_unread')->default(0);
            $table->timestamps();

            $table->unique('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
