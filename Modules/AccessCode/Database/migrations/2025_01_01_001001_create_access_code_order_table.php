<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('access_code_order')) {
            return;
        }

        Schema::create('access_code_order', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('access_code_id');
            $table->unsignedBigInteger('order_id');
            $table->timestamp('assigned_at')->comment('Thời điểm gán mã cho order');
            $table->timestamps();

            $table->index('access_code_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_code_order');
    }
};
