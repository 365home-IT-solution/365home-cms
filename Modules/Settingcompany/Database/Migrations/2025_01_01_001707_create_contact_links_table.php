<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_links')) {
            return;
        }

        Schema::create('contact_links', function (Blueprint $table) {
            $table->id();
            $table->string('facebook_messenger_link')->nullable();
            $table->string('zalo_link')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('text_color')->nullable();
            $table->enum('position', ['bottom-left', 'bottom-right'])->default('bottom-right');
            $table->integer('bottom_offset')->default(20)->comment('Khoảng cách từ đáy tính theo px');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_links');
    }
};
