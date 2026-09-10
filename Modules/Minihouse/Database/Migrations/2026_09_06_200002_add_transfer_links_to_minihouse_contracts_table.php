<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Liên kết 1-1 giữa hợp đồng CŨ (phòng đang trả) và hợp đồng MỚI (phòng vừa chuyển sang) khi dùng
// action "Chuyển phòng" (EditContract) — trước đây chuyển phòng phải kết thúc hẳn 1 hợp đồng rồi tự
// tạo hợp đồng mới tay, không có gì nối lại lịch sử 2 hợp đồng đó là CÙNG 1 lần thuê, chỉ đổi phòng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->foreignId('transferred_to_contract_id')->nullable()->unique()
                ->after('status')->constrained('minihouse_contracts')->nullOnDelete();
            $table->foreignId('transferred_from_contract_id')->nullable()->unique()
                ->after('transferred_to_contract_id')->constrained('minihouse_contracts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferred_to_contract_id');
            $table->dropConstrainedForeignId('transferred_from_contract_id');
        });
    }
};
