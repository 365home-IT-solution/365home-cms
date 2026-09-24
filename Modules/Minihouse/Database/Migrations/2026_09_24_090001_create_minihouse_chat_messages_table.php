<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('conversation_id');
            // Chia luồng theo TỪNG HỢP ĐỒNG (mirror order_id của App\Models\ChatMessage — Home) —
            // NULL = luồng "hỗ trợ chung", khác NULL = luồng riêng của đúng hợp đồng đó. 1 khách
            // thuê có thể có NHIỀU hợp đồng theo thời gian (chuyển phòng qua Contract::transferRoom(),
            // gia hạn tạo hợp đồng mới...) nên vẫn cần chia luồng dù ít "giao dịch" hơn Home.
            $table->unsignedBigInteger('contract_id')->nullable();
            // 'tenant' | 'admin' — cùng quy ước sender_type/sender_id của App\Models\ChatMessage.
            $table->string('sender_type', 20);
            // Đa hình theo sender_type: tenant_id (minihouse_tenants, số nguyên) hoặc user_id
            // (users, uuid) — lưu string để chứa được cả 2 dạng, KHÔNG đặt khoá ngoại (đa hình).
            $table->string('sender_id', 40);
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('minihouse_chat_conversations')->cascadeOnDelete();
            $table->foreign('contract_id')->references('id')->on('minihouse_contracts')->nullOnDelete();
            $table->index(['conversation_id', 'created_at']);
            $table->index('contract_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_chat_messages');
    }
};
