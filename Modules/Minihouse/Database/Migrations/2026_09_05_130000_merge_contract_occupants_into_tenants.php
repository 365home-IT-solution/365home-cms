<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Gộp "Người ở cùng" (minihouse_contract_occupants) vào chung sổ Khách thuê — mọi người trong
// phòng (kể cả trẻ em) giờ đều là 1 bản ghi Tenant thật, có đủ hồ sơ (quét CCCD, ngày sinh, giới
// tính, nơi thường trú...) thay vì 2 model song song trùng field. Hợp đồng → Khách thuê giờ là
// quan hệ NHIỀU-NHIỀU qua bảng minihouse_contract_tenants (cột `role`: 'primary' = người đứng tên
// hợp đồng gốc, 'occupant' = người ở cùng) — Contract.tenant_id VẪN GIỮ NGUYÊN làm nguồn xác định
// "ai đứng tên chính" (không đổi hành vi hàng loạt Form/Table/Widget đang đọc field này), bảng mới
// chỉ bổ sung thêm để truy vấn đầy đủ mọi người liên quan tới 1 hợp đồng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('minihouse_tenants')->cascadeOnDelete();
            $table->string('role')->default('occupant'); // primary | occupant
            $table->string('relationship_to_primary')->nullable(); // Quan hệ với người đứng tên
            $table->timestamps();
            $table->unique(['contract_id', 'tenant_id']);
        });

        // 1) Mirror "người đứng tên" hiện có (Contract.tenant_id) vào bảng mới, role=primary.
        $contracts = DB::table('minihouse_contracts')->whereNotNull('tenant_id')->get(['id', 'tenant_id']);
        $now = now();
        foreach ($contracts as $contract) {
            DB::table('minihouse_contract_tenants')->insert([
                'contract_id' => $contract->id,
                'tenant_id'   => $contract->tenant_id,
                'role'        => 'primary',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // 2) Biến mỗi "Người ở cùng" cũ thành 1 Khách thuê thật + 1 dòng role=occupant, ghi nhớ
        // occupant_id -> tenant_id mới để cập nhật lại minihouse_residence_declarations bên dưới.
        $occupantToTenant = [];
        $occupants = DB::table('minihouse_contract_occupants')->get();

        foreach ($occupants as $occupant) {
            $newTenantId = DB::table('minihouse_tenants')->insertGetId([
                'fullname'               => $occupant->fullname,
                'id_card_number'         => $occupant->id_card_number,
                'id_card_front'          => $occupant->id_card_front,
                'id_card_back'           => $occupant->id_card_back,
                'date_of_birth'          => $occupant->date_of_birth,
                'gender'                 => $occupant->gender,
                'permanent_address'      => $occupant->permanent_address,
                'residence_declared'     => $occupant->residence_declared,
                'residence_declared_at'  => $occupant->residence_declared_at,
                'created_at'             => $occupant->created_at,
                'updated_at'             => $now,
            ]);

            DB::table('minihouse_contract_tenants')->insert([
                'contract_id'              => $occupant->contract_id,
                'tenant_id'                => $newTenantId,
                'role'                     => 'occupant',
                'relationship_to_primary'  => $occupant->relationship,
                'created_at'               => $occupant->created_at,
                'updated_at'               => $now,
            ]);

            $occupantToTenant[$occupant->id] = $newTenantId;
        }

        // 3) minihouse_residence_declarations: chuyển occupant_id -> tenant_id (Tenant mới tạo ở
        // bước 2), rồi bỏ hẳn cột occupant_id — mọi bản ghi lưu trú giờ khoá theo (contract_id,
        // tenant_id) đồng nhất, không phân biệt "loại chủ thể" nữa.
        foreach ($occupantToTenant as $oldOccupantId => $newTenantId) {
            DB::table('minihouse_residence_declarations')
                ->where('occupant_id', $oldOccupantId)
                ->update(['tenant_id' => $newTenantId, 'occupant_id' => null]);
        }

        Schema::table('minihouse_residence_declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('occupant_id');
        });

        Schema::dropIfExists('minihouse_contract_occupants');
    }

    public function down(): void
    {
        // Refactor 1 chiều — dữ liệu occupant cũ đã hoà vào minihouse_tenants, không tách ngược
        // lại được an toàn. Chỉ khôi phục cấu trúc bảng rỗng để tránh kẹt migrate:rollback.
        Schema::create('minihouse_contract_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->string('fullname');
            $table->string('id_card_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('id_card_front')->nullable();
            $table->string('id_card_back')->nullable();
            $table->string('relationship')->nullable();
            $table->boolean('residence_declared')->default(false);
            $table->date('residence_declared_at')->nullable();
            $table->timestamps();
        });

        Schema::table('minihouse_residence_declarations', function (Blueprint $table) {
            $table->foreignId('occupant_id')->nullable()->after('tenant_id')->constrained('minihouse_contract_occupants')->cascadeOnDelete();
        });

        Schema::dropIfExists('minihouse_contract_tenants');
    }
};
