<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chat khách thuê <-> nhân viên toà nhà — mirror kiến trúc App\Models\ChatConversation của Home.
// CHỈ mở cho khách ĐÃ KÝ HỢP ĐỒNG (tenant_id bắt buộc + unique — 1 khách thuê đúng 1 conversation
// trong suốt vòng đời) — khách tiềm năng/chưa ký hợp đồng KHÔNG chat, chỉ gửi "yêu cầu liên hệ" qua
// App\Http\Controllers\Api\Minihouse\Public\RentalInquiryController để nhân viên tự gọi lại tư vấn.
//
// building_id lưu THẲNG trên conversation (KHÁC Home — ChatConversation của Home không có cột chi
// nhánh, phải lọc gián tiếp qua messages->order->category_id) — MiniHouse đã có sẵn kiến trúc lọc
// theo building_id ở khắp nơi (ScopedToActiveBuildingViaRoom, ScopesToMinihouseBuilding), lưu cột
// thật ở đây cho nhất quán và lọc nhanh hơn hẳn so với đi vòng qua quan hệ — đây là cải tiến CHỦ Ý,
// không phải thiếu sót. Chốt theo hợp đồng ĐANG HIỆU LỰC tại thời điểm TẠO LẦN ĐẦU, không tự đổi lại
// nếu khách sau này chuyển phòng/hết hợp đồng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->unsignedBigInteger('building_id')->nullable();
            // Con trỏ hợp đồng đang xem gần nhất (mirror order_id của Home) — không phải ranh giới
            // lọc, chỉ để mở lại đúng luồng khách/nhân viên vừa xem lần trước. Luôn rỗng cho tới khi
            // có hợp đồng đầu tiên.
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('last_message_preview', 200)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedSmallInteger('admin_unread')->default(0);
            $table->unsignedSmallInteger('tenant_unread')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('minihouse_tenants')->cascadeOnDelete();
            $table->foreign('building_id')->references('id')->on('categories')->nullOnDelete();
            $table->foreign('contract_id')->references('id')->on('minihouse_contracts')->nullOnDelete();
            $table->index('building_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_chat_conversations');
    }
};
