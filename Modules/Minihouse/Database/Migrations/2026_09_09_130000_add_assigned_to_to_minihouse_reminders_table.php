<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Giao nhắc việc (chủ yếu "Nhắc bảo trì") cho ĐÚNG 1 nhân viên cụ thể — trước đây mọi nhắc việc chỉ
// hiện chung cho bất kỳ ai xem trang Nhắc việc, không rõ ai chịu trách nhiệm xử lý, dễ bị đùn đẩy khi
// có nhiều nhân viên. nullOnDelete() vì xoá tài khoản không nên xoá luôn nhắc việc đã giao.
// users.id là UUID (char(36), không phải bigint) — PHẢI dùng foreignUuid(), không phải foreignId()
// (lần chạy đầu 2026-09-09 dùng nhầm foreignId(), FK tạo thất bại lỗi 3780 "incompatible", cột được
// tạo nhưng KHÔNG có constraint — dropColumnIfExists() ở đây để chạy lại sạch không lỗi trùng cột).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('minihouse_reminders', 'assigned_to')) {
            Schema::table('minihouse_reminders', function (Blueprint $table) {
                $table->dropColumn('assigned_to');
            });
        }

        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->foreignUuid('assigned_to')->nullable()->after('invoice_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
        });
    }
};
