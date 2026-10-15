<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mỗi đối tác có các bảng giá (price_boards) riêng, mỗi bảng áp cho 1 chi nhánh — thay cho "bảng giá
// mẫu" dùng chung (price_templates) và bảng theo dõi dữ liệu mẫu (sample_data_records) đã bỏ.
// Bảng mặc định (is_default) vẫn là 1 bảng toàn hệ thống, partner_id/category_id để NULL.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('price_boards', 'partner_id')) {
            Schema::table('price_boards', function (Blueprint $table) {
                $table->uuid('partner_id')->nullable()->after('id');
                $table->unsignedBigInteger('category_id')->nullable()->after('partner_id');

                $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
                $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
                $table->index(['partner_id', 'category_id']);
            });
        }

        // Bảng đã có: đối tác = đối tác của phòng đầu tiên trong bảng.
        DB::table('price_boards')
            ->where('is_default', false)
            ->whereNull('partner_id')
            ->orderBy('id')
            ->each(function ($board) {
                $partnerId = DB::table('price_board_items')
                    ->join('products', 'products.id', '=', 'price_board_items.product_id')
                    ->where('price_board_items.price_board_id', $board->id)
                    ->whereNotNull('products.partner_id')
                    ->value('products.partner_id');

                if ($partnerId) {
                    DB::table('price_boards')->where('id', $board->id)->update(['partner_id' => $partnerId]);
                }
            });

        Schema::dropIfExists('price_template_slots');
        Schema::dropIfExists('price_templates');
        Schema::dropIfExists('sample_data_records');
    }

    public function down(): void
    {
        Schema::table('price_boards', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropForeign(['category_id']);
            $table->dropIndex(['partner_id', 'category_id']);
            $table->dropColumn(['partner_id', 'category_id']);
        });
    }
};
