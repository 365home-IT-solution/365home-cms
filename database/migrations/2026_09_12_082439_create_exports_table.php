<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_disk');
            $table->string('file_name')->nullable();
            $table->string('exporter');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            // cms_users.id là char(36) UUID (không phải bigint auto-increment) — stub gốc của
            // Filament dùng foreignId()->constrained() giả định khoá chính dạng số, gây lỗi "column
            // ... are incompatible" khi chạy migrate. Dùng uuid() KHÔNG kèm FK cứng, cùng quy ước đã
            // áp dụng cho audit_logs/timeslot_holds (2 bảng cũng tham chiếu users.id kiểu UUID).
            $table->uuid('user_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
