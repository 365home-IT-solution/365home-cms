<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // LUỒNG TIỀN MỚI (tiền đặt phòng về thẳng đối tác + ký quỹ + đối soát hoa hồng) chỉ có hiệu lực với đối tác SAU KHI
    // hợp đồng mẫu mới (hoặc phụ lục ký quỹ & thanh toán) được cả hai bên ký — trước đó 365home vẫn thu hộ.
    //  - partners.payment_flow_effective_at: mốc luồng mới có hiệu lực (null = còn thu hộ).
    //  - partner_contract_versions.kind: 'contract' (hợp đồng) | 'addendum' (phụ lục cho đối tác đã ký hợp đồng cũ).
    public function up(): void
    {
        if (! Schema::hasColumn('partners', 'payment_flow_effective_at')) {
            Schema::table('partners', function (Blueprint $table) {
                $table->timestamp('payment_flow_effective_at')->nullable();
            });
        }

        if (! Schema::hasColumn('partner_contract_versions', 'kind')) {
            Schema::table('partner_contract_versions', function (Blueprint $table) {
                $table->string('kind', 20)->default('contract');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partner_contract_versions', 'kind')) {
            Schema::table('partner_contract_versions', fn (Blueprint $table) => $table->dropColumn('kind'));
        }

        if (Schema::hasColumn('partners', 'payment_flow_effective_at')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('payment_flow_effective_at'));
        }
    }
};
