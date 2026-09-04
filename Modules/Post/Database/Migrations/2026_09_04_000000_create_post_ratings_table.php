<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('post_ratings')) {
            return;
        }

        Schema::create('post_ratings', function (Blueprint $table) {
            $table->id();
            // posts.id là ULID char(26) (HasUlids), không phải bigint tự tăng — cột tham chiếu
            // phải khớp kiểu, không dùng foreignId() mặc định.
            $table->char('post_id', 26);
            $table->unsignedTinyInteger('rating');
            // Định danh người bình chọn để chặn spam 1 người bấm nhiều lần: sha256(ip + user-agent).
            // Không dùng cookie/tài khoản vì độc giả không cần đăng nhập mới được đánh giá.
            $table->string('voter_hash', 64);
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->unique(['post_id', 'voter_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_ratings');
    }
};
