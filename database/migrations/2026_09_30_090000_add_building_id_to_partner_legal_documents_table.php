<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('partner_id')
                ->constrained('categories')->nullOnDelete();
            $table->index(['partner_id', 'building_id', 'type'], 'partner_legal_building_type_idx');
        });

        // Chữa dữ liệu MiniHouse cũ: tòa nhà là nguồn sự thật của partner_id cho phòng và camera.
        DB::table('categories')->whereNotNull('partner_id')->orderBy('id')->chunkById(200, function ($buildings) {
            foreach ($buildings as $building) {
                DB::table('products')->where('building_id', $building->id)
                    ->update(['partner_id' => $building->partner_id]);
                DB::table('cameras')->where('branch_id', $building->id)
                    ->update(['partner_id' => $building->partner_id]);
            }
        });

        // Hồ sơ cấp tòa nhà cũ chưa có building_id. Chỉ tự gán khi Partner có đúng một tòa;
        // trường hợp nhiều tòa giữ nguyên để Super Admin xác minh, tránh đoán sai phạm vi pháp lý.
        DB::table('partners')->where('partner_type', 'minihouse')->pluck('id')->each(function ($partnerId) {
            $buildingIds = DB::table('categories')->where('partner_id', $partnerId)
                ->where('category_type', 'product')->whereNull('parent_id')->pluck('id');
            if ($buildingIds->count() === 1) {
                DB::table('partner_legal_documents')->where('partner_id', $partnerId)
                    ->whereNull('building_id')
                    ->whereIn('type', ['fire_safety', 'security_order', 'property_ownership_or_use'])
                    ->update(['building_id' => $buildingIds->first()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('partner_legal_documents', function (Blueprint $table) {
            $table->dropIndex('partner_legal_building_type_idx');
            $table->dropConstrainedForeignId('building_id');
        });
    }
};
