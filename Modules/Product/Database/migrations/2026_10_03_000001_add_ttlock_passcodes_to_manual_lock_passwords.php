<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bộ mật khẩu cấp qua "Cấp mã mở hàng loạt" (TTLock) — lưu lại mã đã cài lên khóa nào với
// keyboardPwdId nào ([{lock_id, keyboard_pwd_id}]), để khi SỬA thời gian / XÓA bộ mật khẩu thì
// đồng bộ được xuống khóa (TTLock /v3/keyboardPwd/change, /delete) — nếu không mã cũ vẫn mở được
// cửa dù bản ghi đã sửa/xóa. NULL = bộ mật khẩu nhập tay/Import Excel (không gắn khóa TTLock).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_lock_passwords', function (Blueprint $table) {
            $table->json('ttlock_passcodes')->nullable()->after('room_password');
        });
    }

    public function down(): void
    {
        Schema::table('manual_lock_passwords', function (Blueprint $table) {
            $table->dropColumn('ttlock_passcodes');
        });
    }
};
