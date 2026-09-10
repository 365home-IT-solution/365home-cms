<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// unique(contract_id, month) KHÔNG biết gì về "xoá mềm" — hàng đã xoá mềm (deleted_at có giá trị)
// vẫn CHIẾM giữ giá trị (contract_id, month) trong index, nên sau khi xoá 1 hoá đơn thì KHÔNG BAO
// GIỜ tạo lại được hoá đơn khác cho đúng hợp đồng + tháng đó nữa (Lập hoá đơn hàng loạt lẫn tạo tay
// đều bị chặn ở tầng DB, InvoiceGenerationService chỉ âm thầm coi là "đã có" mà không báo lỗi rõ).
// Xác nhận thật: xoá 1 hoá đơn rồi bấm "Lập hoá đơn hàng loạt" lại cho đúng hợp đồng+tháng đó bị
// SKIP thay vì tạo mới. MySQL không hỗ trợ unique index có điều kiện (loại trừ deleted_at) nên bỏ
// hẳn constraint này — chặn trùng hoá đơn ĐANG HOẠT ĐỘNG chuyển hẳn sang tầng ứng dụng (xem
// InvoiceGenerationService::generateForMonth() đã tự kiểm tra đúng qua Eloquent — tự động loại trừ
// bản ghi đã xoá mềm — và CreateInvoice::mutateFormDataBeforeCreate() cho tạo tay ở Filament).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            // FK contract_id->minihouse_contracts CẦN 1 index bắt đầu bằng contract_id để InnoDB hỗ
            // trợ — index unique(contract_id, month) sắp xoá bên dưới ĐANG LÀ index duy nhất đó, phải
            // thêm 1 index thường thay thế TRƯỚC, nếu không MySQL từ chối xoá (lỗi 1553).
            $table->index('contract_id', 'minihouse_invoices_contract_id_index');
            $table->dropUnique('minihouse_invoices_contract_month_unique');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_invoices', function (Blueprint $table) {
            $table->unique(['contract_id', 'month'], 'minihouse_invoices_contract_month_unique');
            $table->dropIndex('minihouse_invoices_contract_id_index');
        });
    }
};
