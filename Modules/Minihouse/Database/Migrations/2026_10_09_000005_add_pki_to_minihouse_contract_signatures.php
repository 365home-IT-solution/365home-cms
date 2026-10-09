<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MiniHouse Mức C: chủ trọ ký SỐ (PAdES, chứng thư số CA) lên PDF cuối của hợp đồng thuê — ghi lại nhà cung cấp và chứng thư đã dùng để
    // đối chiếu/xác minh sau này. Khách thuê vẫn ký Mức A (vẽ tay + OTP).
    public function up(): void
    {
        if (Schema::hasTable('minihouse_contract_signatures') && ! Schema::hasColumn('minihouse_contract_signatures', 'pki_provider')) {
            Schema::table('minihouse_contract_signatures', function (Blueprint $table) {
                $table->string('pki_provider', 40)->nullable();
                $table->json('pki_certificate')->nullable();
                $table->timestamp('pki_signed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('minihouse_contract_signatures', 'pki_provider')) {
            Schema::table('minihouse_contract_signatures', fn (Blueprint $table) => $table->dropColumn(['pki_provider', 'pki_certificate', 'pki_signed_at']));
        }
    }
};
