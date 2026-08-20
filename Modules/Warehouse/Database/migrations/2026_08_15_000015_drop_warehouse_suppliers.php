<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bỏ hẳn khái niệm "Nhà cung cấp" khỏi phiếu nhập kho (yêu cầu: "bỏ luôn phần nhà cung cấp và
// module nhà cung cấp") — xác nhận KHÔNG có dữ liệu thật nào phụ thuộc (0 nhà cung cấp, 0 phiếu
// nhập nào gán warehouse_supplier_id) trước khi xóa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_stock_ins', function (Blueprint $table) {
            $table->dropForeign(['warehouse_supplier_id']);
            $table->dropColumn('warehouse_supplier_id');
        });

        Schema::dropIfExists('warehouse_suppliers');
    }

    public function down(): void
    {
        Schema::create('warehouse_suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('note')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });

        Schema::table('warehouse_stock_ins', function (Blueprint $table) {
            $table->foreignId('warehouse_supplier_id')->nullable()->after('code')->constrained('warehouse_suppliers')->nullOnDelete();
        });
    }
};
