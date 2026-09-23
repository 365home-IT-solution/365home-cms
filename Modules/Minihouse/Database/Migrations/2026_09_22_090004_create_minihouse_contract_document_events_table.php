<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nhật ký chỉ-thêm cho từng bản hợp đồng điện tử — in kèm hợp đồng dạng "Biên bản quá trình ký"
// (GET .../document/audit). actor_id string cùng lý do signer_id ở minihouse_contract_signatures
// (trỏ Tenant int HOẶC User uuid tuỳ actor_type).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_contract_document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('minihouse_contract_documents')->cascadeOnDelete();

            // created·updated·sealed·sent·viewed_by_tenant·otp_sent·otp_failed·signed_by_tenant·
            // signed_by_owner·finalized·recalled·cancelled
            $table->string('event');

            $table->string('actor_type')->nullable(); // minihouse_tenant | admin_user | system
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_contract_document_events');
    }
};
