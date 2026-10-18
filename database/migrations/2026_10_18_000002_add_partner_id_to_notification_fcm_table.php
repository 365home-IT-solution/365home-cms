<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Push thủ công của đối tác chỉ gửi tới khách của đối tác đó → cần biết bản ghi thuộc đối tác nào.
// NULL = thông báo cấp nền tảng (super_admin gửi khi chưa chọn đối tác, hoặc thông báo hệ thống tự động).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('notification_fcm', 'partner_id')) {
            return;
        }

        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->uuid('partner_id')->nullable()->after('created_by');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('notification_fcm', 'partner_id')) {
            return;
        }

        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
