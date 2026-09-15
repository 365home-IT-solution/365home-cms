<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Danh mục "Loại tài sản" (tủ lạnh, máy lạnh, giường, tủ...) dùng CHUNG cho mọi phòng — trước đây
// mỗi phòng phải gõ tay tên tài sản riêng ở Repeater (RoomForm), nhiều phòng thì nhập rất chậm và dễ
// gõ lệch tên (VD "Máy lạnh" / "máy lạnh" / "Điều hoà" cho cùng 1 loại). Giờ tạo 1 lần ở danh mục
// này, chọn lại ở phòng — mirror đúng kiến trúc "Tiện ích" (minihouse_amenities) đã có, nhưng KHÔNG
// dùng bảng trung gian nhiều-nhiều vì mỗi tài sản GẮN VỚI 1 PHÒNG CỤ THỂ còn cần tình trạng/ghi chú
// riêng của phòng đó (khác tiện ích chỉ cần có/không) — minihouse_room_assets.name vẫn là cột string
// bình thường, chỉ đổi Ô NHẬP ở form thành chọn từ danh mục này thay vì gõ tay tự do.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_asset_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Nạp sẵn danh mục từ tên tài sản ĐÃ có sẵn trên các phòng hiện tại (nếu có, nhập tay tự do
        // trước khi có bảng này) — để đổi Repeater sang chọn (Select) không làm "biến mất" các tên đã
        // dùng, dữ liệu cũ vẫn chọn lại được ngay.
        $now = now();
        $existingNames = \Illuminate\Support\Facades\DB::table('minihouse_room_assets')
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->distinct()
            ->pluck('name');

        if ($existingNames->isNotEmpty()) {
            \Illuminate\Support\Facades\DB::table('minihouse_asset_types')->insert(
                $existingNames->map(fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_asset_types');
    }
};
