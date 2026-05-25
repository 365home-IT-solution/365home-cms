<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ttlock_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Tên hiển thị trong admin');
            $table->string('client_id');
            $table->string('client_secret');
            $table->string('username');
            $table->string('password_md5', 32)->comment('MD5 lowercase của mật khẩu TTLock App');
            $table->string('api_base')->default('https://euapi.ttlock.com');
            $table->unsignedBigInteger('category_id')->nullable()->comment('Chi nhánh được gán — null = dùng chung');
            $table->boolean('is_default')->default(false)->comment('Fallback khi chi nhánh không có account riêng');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->unique('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ttlock_accounts');
    }
};
