<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('unlock_both_locks')
                ->default(false)
                ->after('has_manual_lock')
                ->comment('Cửa cần mở CẢ 2 ổ (lock_id + lock_id_checkout) CÙNG LÚC mới thật sự mở được — khác với mặc định (mỗi ổ dùng ở 1 thời điểm riêng: ngoài cho check-in, trong cho check-out)');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unlock_both_locks');
        });
    }
};
