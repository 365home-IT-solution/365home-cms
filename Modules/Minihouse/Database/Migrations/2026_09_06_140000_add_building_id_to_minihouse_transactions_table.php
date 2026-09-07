<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Thu chi hiện KHÔNG lọc được theo toà nhà (contract_id nullable — có giao dịch vận hành/sửa chữa
// chung của cả toà, không gắn hợp đồng nào) — phát hiện qua audit toàn module: tài khoản bị giới
// hạn quản lý riêng 1 toà vẫn thấy được thu/chi của toà khác qua MinihouseStatsWidget và
// TransactionResource. Thêm building_id trực tiếp (giống Room/Surcharge) để lọc được cả giao dịch
// không gắn hợp đồng cụ thể — KHÔNG bắt buộc NOT NULL vì dữ liệu cũ (đã tạo trước migration này) có
// thể không suy ra được toà nhà nếu contract đã bị xoá cứng.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('minihouse_transactions', 'building_id')) {
            Schema::table('minihouse_transactions', function (Blueprint $table) {
                $table->foreignId('building_id')->nullable()->after('contract_id')->constrained('minihouse_buildings')->nullOnDelete();
            });
        }

        // Suy ra building_id cho dữ liệu cũ từ contract -> room -> building, chỗ nào còn suy ra
        // được (không suy ra được thì để null — sẽ ẩn khỏi tài khoản bị giới hạn, đúng hướng an
        // toàn hơn là lộ dữ liệu). Dùng query builder để tự động cộng đúng prefix bảng cms_ của dự
        // án cho TÊN BẢNG/ALIAS — nhưng alias khi viết "table as alias" cũng bị cộng prefix theo
        // (hành vi riêng của Laravel), nên vế phải của update() phải tự ghép prefix thủ công qua
        // DB::raw(), không thể viết thẳng "r.building_id".
        $prefix = DB::connection()->getTablePrefix();

        DB::table('minihouse_transactions as t')
            ->join('minihouse_contracts as c', 'c.id', '=', 't.contract_id')
            ->join('minihouse_rooms as r', 'r.id', '=', 'c.room_id')
            ->whereNull('t.building_id')
            ->update(['t.building_id' => DB::raw("{$prefix}r.building_id")]);
    }

    public function down(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });
    }
};
