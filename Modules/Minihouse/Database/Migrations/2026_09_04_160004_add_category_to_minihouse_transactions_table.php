<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            // Chỉ áp dụng cho type='chi' — vd sửa chữa/vận hành, xem TransactionForm.
            $table->string('category')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_transactions', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
