<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Tài khoản admin (đối tác) đã tạo nhân viên này — dùng để mỗi đối tác chỉ thấy/quản lý
            // được nhân viên của chính mình (và của các admin con do mình tạo), trừ super_admin thấy hết.
            $table->uuid('created_by')->nullable()->after('id');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
