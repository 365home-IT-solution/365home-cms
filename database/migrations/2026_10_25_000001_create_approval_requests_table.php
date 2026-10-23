<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DUYỆT TRƯỚC KHI ĐĂNG của đối tác: bảng giá (tạo mới / sửa) và phòng (đăng mới / sửa thông tin, sửa giá) phải được 365home duyệt mới có hiệu lực.
// approval_status trên price_boards / products = trạng thái của CHÍNH bản ghi (approved mặc định cho dữ liệu cũ; pending = chờ duyệt, chưa đăng; rejected = bị từ chối).
// Sửa một bản ghi ĐÃ duyệt không đổi approval_status: bản cũ vẫn chạy, bản sửa nằm ở approval_requests.payload chờ duyệt. Xem App\Services\ApprovalService.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approval_requests')) {
            Schema::create('approval_requests', function (Blueprint $table) {
                $table->id();
                $table->char('partner_id', 36)->index();
                $table->string('kind', 30);            // price_board_create | price_board_update | room_create | room_update
                $table->string('subject_type', 20);    // price_board | product
                $table->string('subject_id', 40);
                $table->string('subject_label')->nullable(); // tên bảng giá / phòng tại thời điểm gửi
                $table->json('payload')->nullable();   // bản sửa chờ duyệt (spec bảng giá, hoặc các cột phòng / khung giờ)
                $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected | cancelled
                $table->string('requested_by', 40)->nullable();
                $table->string('reviewed_by', 40)->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable(); // lý do từ chối / ghi chú duyệt
                $table->timestamps();

                $table->index(['subject_type', 'subject_id', 'status']);
            });
        }

        foreach (['price_boards', 'products'] as $name) {
            if (! Schema::hasColumn($name, 'approval_status')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->string('approval_status', 12)->default('approved')->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['price_boards', 'products'] as $name) {
            if (Schema::hasColumn($name, 'approval_status')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->dropIndex(['approval_status']);
                    $table->dropColumn('approval_status');
                });
            }
        }

        Schema::dropIfExists('approval_requests');
    }
};
