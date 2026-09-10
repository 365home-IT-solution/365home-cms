<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Người ở cùng" (đồng thuê) — 1 hợp đồng có 1 khách đứng tên (minihouse_tenants) nhưng thực
        // tế phòng trọ/chung cư thường có thêm người ở chung (vợ/chồng, con, bạn ở ghép...). Cần khai
        // báo đủ để: tính đúng số người ở (điện/nước theo đầu người) và khai báo tạm trú (trách nhiệm
        // pháp lý thật của chủ trọ) — không lồng vào minihouse_tenants vì đây không phải người đứng
        // tên hợp đồng, không cần tài khoản/hồ sơ đầy đủ như Tenant.
        Schema::create('minihouse_contract_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->string('fullname');
            $table->string('id_card_number')->nullable();
            $table->string('id_card_front')->nullable();
            $table->string('id_card_back')->nullable();
            $table->string('relationship')->nullable(); // vd: vợ/chồng, con, bạn ở ghép...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_occupants');
    }
};
