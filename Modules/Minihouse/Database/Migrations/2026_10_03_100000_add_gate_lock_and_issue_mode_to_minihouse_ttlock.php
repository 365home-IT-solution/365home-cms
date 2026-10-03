<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Khoá CỔNG của toà nhà + thời điểm cấp mã — xem ContractTtlockService:
//   - gate_lock_ids: các ổ khoá cổng chung của toà (mọi hợp đồng hiệu lực đều được cấp mã trên các ổ này).
//   - gate_code_mode: 'shared' = mã cổng TRÙNG mã phòng (khách nhớ 1 số), 'separate' = mã cổng RIÊNG.
//   - issue_mode: 'contract' = cấp ngay khi tạo hợp đồng (như cũ), 'payment' = chỉ cấp khi đã thu cọc
//     HOẶC đã thanh toán hoá đơn đầu tiên.
// deposit_paid_at: thời điểm xác nhận đã thu cọc (null = chưa thu / hợp đồng không cọc).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_ttlock_settings', function (Blueprint $table) {
            $table->json('gate_lock_ids')->nullable()->after('is_active');
            $table->string('gate_code_mode', 20)->default('shared')->after('gate_lock_ids');
            $table->string('issue_mode', 20)->default('contract')->after('gate_code_mode');
        });

        Schema::table('minihouse_contract_ttlock_passcodes', function (Blueprint $table) {
            $table->boolean('is_gate')->default(false)->after('lock_id');
        });

        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dateTime('deposit_paid_at')->nullable()->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_ttlock_settings', function (Blueprint $table) {
            $table->dropColumn(['gate_lock_ids', 'gate_code_mode', 'issue_mode']);
        });

        Schema::table('minihouse_contract_ttlock_passcodes', function (Blueprint $table) {
            $table->dropColumn('is_gate');
        });

        Schema::table('minihouse_contracts', function (Blueprint $table) {
            $table->dropColumn('deposit_paid_at');
        });
    }
};
