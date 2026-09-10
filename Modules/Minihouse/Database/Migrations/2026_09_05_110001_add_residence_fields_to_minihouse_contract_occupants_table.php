<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Khai báo tạm trú áp dụng cho MỌI người ở trong phòng, không chỉ người đứng tên hợp đồng —
        // xem giải thích ở migration cùng tên trên minihouse_tenants.
        Schema::table('minihouse_contract_occupants', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('id_card_number');
            $table->string('gender')->nullable()->after('date_of_birth');
            $table->string('permanent_address')->nullable()->after('gender');
            $table->boolean('residence_declared')->default(false)->after('relationship');
            $table->date('residence_declared_at')->nullable()->after('residence_declared');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_contract_occupants', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'gender', 'permanent_address', 'residence_declared', 'residence_declared_at']);
        });
    }
};
