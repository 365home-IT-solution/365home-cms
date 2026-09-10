<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('id_card_number');
            $table->string('gender')->nullable()->after('date_of_birth'); // nam | nu | khac
            $table->string('hometown')->nullable()->after('gender'); // Quê quán
            $table->string('permanent_address')->nullable()->after('hometown'); // Nơi thường trú (theo CCCD)
            $table->string('occupation')->nullable()->after('permanent_address');
            $table->string('workplace')->nullable()->after('occupation');
            $table->string('emergency_contact_name')->nullable()->after('workplace');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            // Trách nhiệm pháp lý của chủ trọ — khai báo tạm trú cho khách thuê trong 12-24h (qua
            // VNeID/công an khu vực). Không có mặc định "đã khai báo" vì mỗi lần chuyển tới 1 phòng
            // mới về nguyên tắc phải khai báo lại.
            $table->boolean('residence_declared')->default(false)->after('emergency_contact_phone');
            $table->date('residence_declared_at')->nullable()->after('residence_declared');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth', 'gender', 'hometown', 'permanent_address',
                'occupation', 'workplace', 'emergency_contact_name', 'emergency_contact_phone',
                'residence_declared', 'residence_declared_at',
            ]);
        });
    }
};
