<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Khai báo lưu trú" — mang từ Home qua (xem App\Models\CccdDeclaration bên hệ Home), theo
        // ĐÚNG mẫu chính thức "Thông báo lưu trú" của Bộ Công an (tblt_vn_import.xlsx, ở gốc dự án,
        // dùng chung cho cả Home lẫn MiniHouse — dữ liệu hành chính quốc gia, không riêng nghiệp vụ
        // nào). KHÔNG tự động gửi ASM/dịch vụ công — chỉ lưu tham chiếu + đánh dấu nội bộ đã nộp
        // thủ công chưa, xem ResidenceDeclaration model.
        //
        // 1 bản ghi = 1 người cần khai báo trong 1 hợp đồng — CHỈ MỘT trong 2 cột tenant_id/
        // occupant_id được điền (người đứng tên hợp đồng, hoặc người ở cùng), không dùng chung 1
        // cột kiểu polymorphic vì chỉ có đúng 2 loại "chủ thể" cố định, tách cột cho dễ query/rõ ràng.
        Schema::create('minihouse_residence_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('minihouse_contracts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('minihouse_tenants')->cascadeOnDelete();
            $table->foreignId('occupant_id')->nullable()->constrained('minihouse_contract_occupants')->cascadeOnDelete();

            $table->string('full_name')->nullable();
            $table->string('date_of_birth')->nullable(); // Lưu chuỗi hiển thị (dd/mm/yyyy) khớp đúng mẫu Excel
            $table->string('gender')->nullable(); // "M - Nam" | "F - Nữ"
            $table->string('cccd_number')->nullable();
            $table->string('nationality')->nullable();
            $table->string('document_type')->nullable();
            $table->string('phone_number')->nullable();

            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('room_number')->nullable();
            $table->string('stay_address')->nullable();

            $table->string('reason_for_stay')->nullable();
            $table->string('custom_reason')->nullable();

            $table->string('current_residence')->nullable();
            $table->string('residence_type')->nullable();
            $table->string('province')->nullable();
            $table->string('ward')->nullable();
            $table->text('address_detail')->nullable();
            $table->text('notes')->nullable();

            $table->dateTime('declared_at')->nullable();
            $table->foreignUuid('declared_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_residence_declarations');
    }
};
