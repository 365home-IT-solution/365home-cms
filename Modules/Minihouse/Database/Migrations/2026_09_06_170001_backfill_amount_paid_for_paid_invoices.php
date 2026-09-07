<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Hoá đơn đã đánh dấu status='paid' TRƯỚC KHI có tính năng theo dõi thanh toán từng lần (không có
// dòng nào trong minihouse_invoice_payments) — amount_paid của chúng đang là 0 (giá trị mặc định
// cột mới thêm), khác với total_amount. Nếu để vậy, lần đầu tiên có ai ghi nhận thêm 1 khoản thanh
// toán cho hoá đơn đó, InvoicePaymentObserver sẽ tính lại và tưởng đây là thanh toán "1 phần" (vì
// chỉ thấy 1 khoản mới, chưa bằng total_amount) — SAI so với thực tế đã thu đủ từ trước. Coi số cũ
// là "đã thu đủ" (đúng ý nghĩa status='paid' cũ), gán amount_paid = total_amount, paid_at =
// updated_at (không biết chính xác ngày thu thật, lấy tạm ngày cập nhật gần nhất).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('minihouse_invoices')
            ->where('status', 'paid')
            ->where('amount_paid', 0)
            ->update([
                'amount_paid' => DB::raw('total_amount'),
                'paid_at'     => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        // Không hoàn tác — không còn cách nào phân biệt lại dòng nào từng được backfill ở đây.
    }
};
