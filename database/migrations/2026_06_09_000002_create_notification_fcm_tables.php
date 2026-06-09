<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_fcm', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->char('created_by', 36)->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unsignedSmallInteger('sent_count')->default(0);
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_fcm_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_fcm_id')
                  ->constrained('notification_fcm')
                  ->cascadeOnDelete();
            $table->uuid('customer_id');
            $table->string('fcm_token', 512)->nullable();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index('notification_fcm_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_fcm_recipients');
        Schema::dropIfExists('notification_fcm');
    }
};
