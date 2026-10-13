<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lá bài chỉ còn là 1 tấm ảnh (tên và mô tả đã nằm sẵn trong ảnh) — bỏ 2 cột chữ để admin upload nhiều ảnh một lượt.
    public function up(): void
    {
        Schema::table('mini_game_cards', function (Blueprint $table) {
            foreach (['title', 'subtitle'] as $column) {
                if (Schema::hasColumn('mini_game_cards', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('mini_game_cards', function (Blueprint $table) {
            $table->string('title')->nullable()->after('mini_game_id');
            $table->string('subtitle')->nullable()->after('title');
        });
    }
};
