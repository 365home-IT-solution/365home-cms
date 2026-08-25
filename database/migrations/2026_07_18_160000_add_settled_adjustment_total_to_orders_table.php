<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Bug: sau khi 1 khoản phát sinh (extra_charge_amount) được XÁC NHẬN đã thu (QR/chuyển khoản/
    // tiền mặt), full_amount vẫn giữ nguyên KHÔNG đổi (cố ý, để dòng "Khách thanh toán đầy đủ"
    // trong Lịch sử thanh toán luôn hiện đúng số tiền tại THỜI ĐIỂM đó, không bị đội lên sau này).
    // Nhưng ExtraChargeService::calculateDiff() lại luôn so calculateRealTotal() với ĐÚNG
    // full_amount gốc — nên nếu không nhớ lại phần đã thu, lần phát sinh KẾ TIẾP sẽ bị tính LẶP
    // LẠI luôn cả phần đã thu trước đó (vd: thêm khung 1 (+200k) → xác nhận thu → thêm khung 2
    // (+200k nữa) lại hiện "+400k" thay vì đúng "+200k", vì hệ thống "quên" 200k đầu đã thu rồi).
    // Cột này lưu TỔNG các khoản phát sinh/hoàn tiền ĐÃ ĐƯỢC XÁC NHẬN xong (dương = đã thu thêm, âm
    // = đã hoàn khách) — dùng làm mốc trừ thêm khi tính chênh lệch mới, để mỗi lần chỉ tính đúng
    // phần MỚI PHÁT SINH kể từ lần xác nhận gần nhất.
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('settled_adjustment_total')->default(0)->after('extra_refund_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('settled_adjustment_total');
        });
    }
};
