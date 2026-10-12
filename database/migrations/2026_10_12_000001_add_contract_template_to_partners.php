<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mẫu hợp đồng áp dụng cho đối tác: 'ct' = trong Thành phố Cần Thơ, 'vt_dl' = ngoài Cần Thơ. Ghi lúc tạo hợp đồng (theo tỉnh/thành của cơ sở) và giữ nguyên
// sau khi ký, để đổi tỉnh về sau không làm đổi luồng tiền của hợp đồng đã ký. Null = đối tác cũ (hợp đồng mẫu trước đây), giữ nguyên hành vi cũ.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('contract_template', 10)->nullable()->after('payment_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('contract_template');
        });
    }
};
