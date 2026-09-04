<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            // Ảnh biên lai/hoá đơn — chủ yếu dùng cho khoản "chi" (sửa chữa/vận hành) để đối soát
            // sau này, xem TransactionForm (chỉ hiện khi type=chi, giống field category).
            $table->string('receipt_image')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            $table->dropColumn('receipt_image');
        });
    }
};
