<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hợp đồng điện tử (Mức A — vẽ tay + OTP + hash + nhật ký, xem docs/be-minihouse-contract-signing.md)
// — 1 hợp đồng thuê (minihouse_contracts) có tối đa 1 bản ký (contract_id unique). Nội dung KHÔNG
// nhân bản tên/CCCD/giá thành cột riêng ở đây khi còn "draft" (đọc sống từ Contract/Tenant/Building
// qua ContractDocumentService::buildFields()) — chỉ đông cứng vào cột `snapshot` lúc gửi cho khách
// (status=awaiting_tenant), từ đó không đọc lại bảng nguồn cho bản này nữa (sửa hồ sơ khách/giá về
// sau không đụng hợp đồng đã gửi).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained('minihouse_contracts')->cascadeOnDelete();

            // draft | awaiting_tenant | awaiting_owner | signed | cancelled — string thường (không
            // dùng DB enum), đúng convention Contract::STATUS_* đang dùng trong module này.
            $table->string('status')->default('draft');

            $table->string('no')->unique()->nullable();
            $table->date('sign_date')->nullable();
            $table->string('signed_place')->nullable();
            $table->unsignedSmallInteger('max_occupants')->nullable();
            $table->unsignedTinyInteger('payment_day')->nullable();
            $table->text('extra_terms')->nullable();

            // Đông cứng lúc send() — nguồn duy nhất để dựng lại HTML/PDF cho bản này từ đó về sau.
            $table->json('snapshot')->nullable();
            $table->string('template_version')->nullable();

            $table->string('sealed_pdf_path')->nullable();
            $table->char('sealed_hash', 64)->nullable();
            $table->timestamp('sealed_at')->nullable();

            // Xem docs/be-minihouse-contract-signing.md phần "Điều chỉnh so với spec gốc" — final_*
            // được ghi ngay khi khách ký xong (1 chữ ký) rồi ghi đè khi chủ ký xong (2 chữ ký, từ đó
            // bất biến), KHÔNG phải chỉ ghi 1 lần lúc đủ 2 chữ ký như tên gợi ý.
            $table->string('final_pdf_path')->nullable();
            $table->char('final_hash', 64)->nullable();

            $table->string('verify_code')->unique()->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_documents');
    }
};
