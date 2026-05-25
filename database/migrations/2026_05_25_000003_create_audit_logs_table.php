<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Người thực hiện — denormalized để log không mất khi user bị xóa
            $table->uuid('user_id');
            $table->string('user_name');
            $table->string('user_email');
            $table->string('performer_role')->default('unknown'); // role tại thời điểm thao tác

            // Thao tác
            $table->enum('action', ['create', 'update', 'delete']);
            $table->string('module', 100);        // 'Role' | 'User' | 'UserBranchPermission'
            $table->string('target_id', 50)->nullable();    // id bản ghi bị tác động
            $table->string('target_label')->nullable();     // tên bản ghi tại thời điểm đó

            // Snapshot dữ liệu
            $table->json('old_values')->nullable(); // giá trị trước
            $table->json('new_values')->nullable(); // giá trị sau

            // Context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('performer_role');
            $table->index('user_id');
            $table->index('module');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
