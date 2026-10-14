<?php

use App\Services\BranchServiceSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dịch vụ đi kèm chuyển từ gắn TỪNG PHÒNG sang gắn theo CHI NHÁNH (category gốc,
    // category_type=product) — 1 dịch vụ thuộc 1 hoặc nhiều chi nhánh, phòng tự kế thừa toàn bộ dịch
    // vụ của chi nhánh nó thuộc về. room_additional_service_assigns VẪN giữ, nhưng giờ chỉ là dữ liệu
    // dẫn xuất do BranchServiceSync tự ghi lại (mọi luồng đặt phòng vẫn đọc $room->additionalServices
    // như cũ, không phải sửa).
    public function up(): void
    {
        if (! Schema::hasTable('additional_service_branches')) {
            Schema::create('additional_service_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('additional_service_id')->constrained('additional_services')->cascadeOnDelete();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

                $table->unique(['additional_service_id', 'category_id'], 'asb_service_category_unique');
            });
        }

        // Backfill: dịch vụ đang gán cho phòng nào thì gán cho chi nhánh của phòng đó — chi nhánh nhận
        // HỢP các dịch vụ của mọi phòng trong nó, rồi đồng bộ lại để mọi phòng cùng chi nhánh có
        // chung 1 bộ dịch vụ.
        $sync  = app(BranchServiceSync::class);
        $pairs = [];

        DB::table('room_additional_service_assigns')->orderBy('id')->get()->each(function ($row) use ($sync, &$pairs) {
            foreach ($sync->rootBranchIdsForRoomId((string) $row->room_id) as $branchId) {
                $pairs["{$row->additional_service_id}-{$branchId}"] = [
                    'additional_service_id' => $row->additional_service_id,
                    'category_id'           => $branchId,
                ];
            }
        });

        if ($pairs) {
            DB::table('additional_service_branches')->insertOrIgnore(array_values($pairs));
        }

        $sync->syncAll();
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_service_branches');
    }
};
