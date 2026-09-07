<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhật ký hoạt động cho TOÀN module MiniHouse — trước đây không có gì ghi lại "ai đã sửa gì, lúc
// nào" ngoài vài cột 'created_by' rời rạc (InvoicePayment/ContractRenewal). Không dùng lại
// Modules\AuditLog\Entities\AuditLog của Home vì bảng đó bắt buộc gắn partner_id (qua
// BelongsToPartner) — MiniHouse không có khái niệm partner, dùng toà nhà làm ranh giới quyền, nên
// tự làm 1 bảng riêng cùng cơ chế (xem LogsMinihouseActivity) nhưng lọc theo building_id để khớp
// đúng hệ thống phân quyền đang có (ActiveBuildingScope) thay vì đụng vào module Home.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_activity_logs', function (Blueprint $table) {
            $table->id();
            // Nullable — có bản ghi không suy ra được toà nhà (VD model liên quan đã bị xoá cứng
            // trước đó) — để trống thay vì đoán sai, đúng hướng an toàn hơn (ẩn khỏi tài khoản giới
            // hạn còn hơn lộ nhầm sang toà khác).
            $table->foreignId('building_id')->nullable()->constrained('minihouse_buildings')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Lưu thêm tên lúc ghi log — tài khoản có thể bị xoá/đổi tên sau này, log vẫn phải đọc
            // được "ai" đã làm, không phụ thuộc join sang bảng users.
            $table->string('user_name')->nullable();
            $table->string('action'); // created | updated | deleted
            $table->string('subject_type'); // FQCN model, vd Modules\Minihouse\App\Models\Contract
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_activity_logs');
    }
};
