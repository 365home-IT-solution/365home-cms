<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 1 dòng = 1 mã mở TTLock đã cấp cho 1 ổ khoá của 1 Hợp đồng — để ContractTtlockService biết mã nào
// đã cấp, cấp lại/sửa hạn khi hợp đồng đổi ngày, và XOÁ ĐÚNG mã khi hợp đồng kết thúc (không phải tự
// nhớ tay). building_id LƯU SẴN (không tra lại qua room) để vẫn xoá được mã trên TTLock dù sau đó
// phòng/toà nhà của hợp đồng đã đổi khác. Tên bảng/khoá ngoại ngắn (mhc_*) vì tiền tố "cms_" làm tên
// tự sinh vượt 64 ký tự của MySQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_ttlock_passcodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->foreign('contract_id', 'mhc_pwd_contract_fk')->references('id')->on('minihouse_contracts')->cascadeOnDelete();
            $table->unsignedBigInteger('building_id');
            $table->unsignedBigInteger('lock_id');
            $table->unsignedBigInteger('keyboard_pwd_id')->nullable();
            $table->string('code', 20)->nullable();
            $table->dateTime('start_date');
            $table->dateTime('end_date')->nullable()->comment('null = vĩnh viễn (hợp đồng chưa có ngày kết thúc)');
            $table->timestamps();

            $table->unique(['contract_id', 'lock_id'], 'mhc_pwd_contract_lock_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_ttlock_passcodes');
    }
};
