<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mỗi lần ký 1 dòng — CHỈ INSERT (ContractDocumentService không bao giờ update/delete bảng này,
// xem docs/be-minihouse-contract-signing.md mục 3.2). signer_id khai báo string (KHÔNG foreignId)
// vì trỏ 2 bảng khác kiểu khoá tuỳ signer_type: minihouse_tenant -> minihouse_tenants.id (int),
// admin_user -> users.id (uuid, xem cách minihouse_contract_renewals.created_by đã dùng uuid).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('minihouse_contract_documents')->cascadeOnDelete();

            $table->string('party'); // tenant | owner
            $table->string('signer_type'); // minihouse_tenant | admin_user
            $table->string('signer_id');
            $table->string('signer_name');
            $table->string('signer_phone')->nullable();

            $table->string('signature_path');

            // Hash của file mà NGƯỜI NÀY đã ký lên (không phải hash sau khi ký xong) — mấu chốt để
            // chứng minh ký đúng nội dung, xem mục 3.2 của tài liệu spec.
            $table->char('signed_document_hash', 64);

            $table->timestamp('signed_at');
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();

            $table->string('auth_method'); // otp_zalo | otp_sms | admin_session
            $table->string('otp_request_id')->nullable();
            $table->timestamp('otp_verified_at')->nullable();

            $table->text('consent_text');

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_signatures');
    }
};
