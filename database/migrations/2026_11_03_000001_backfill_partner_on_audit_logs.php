<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Lịch sử thao tác ghi qua app quản trị (/api/admin/*) trước đây để trống partner_id (chỉ trang quản trị web tự gán) — không lọc
// được theo đối tác, đối tác cũng không thấy lịch sử của mình. Gán lại theo đối tác của người thao tác (users.partner_id), đúng
// giá trị AuditLogger ghi từ nay.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('partner_id')->select('id', 'partner_id')->orderBy('id')
            ->each(fn ($user) => DB::table('audit_logs')
                ->where('user_id', $user->id)
                ->whereNull('partner_id')
                ->update(['partner_id' => $user->partner_id]));
    }

    public function down(): void
    {
        // Không hoàn tác: không phân biệt được dòng nào vốn để trống.
    }
};
