<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Link chia sẻ tải hợp đồng đối tác (kiểu "chia sẻ link" của Google Drive): ai có link mở được trang tải PDF của đúng 1 phiên bản hợp đồng.
// Mặc định không hết hạn, không mật khẩu; Super Admin / chủ đối tác thu hồi được bất cứ lúc nào. Mọi lượt xem/tải được ghi vào bảng accesses.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_share_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->foreignId('partner_contract_version_id')->constrained('partner_contract_versions')->cascadeOnDelete();
            // Tra cứu bằng sha256 của token (token gốc không nằm trong URL nào khác); token lưu mã hoá để admin sao chép lại link.
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->string('password')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->char('revoked_by', 36)->nullable();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->char('created_by', 36)->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['partner_id', 'revoked_at']);
        });

        Schema::create('contract_share_link_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_share_link_id')->constrained('contract_share_links')->cascadeOnDelete();
            // view (mở trang) | preview (xem file) | download | password_failed
            $table->string('action', 20);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['contract_share_link_id', 'created_at'], 'csla_link_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_share_link_accesses');
        Schema::dropIfExists('contract_share_links');
    }
};
