<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mô tả chi tiết + hướng dẫn chơi của mini game (HTML, soạn bằng TinyEditor ở admin như mô tả dài của phòng) — app hiển thị
    // ở màn game. Cột `description` cũ vẫn là dòng mô tả ngắn.
    public function up(): void
    {
        if (Schema::hasColumn('mini_games', 'long_description')) {
            return;
        }

        Schema::table('mini_games', function (Blueprint $table) {
            $table->longText('long_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('mini_games', function (Blueprint $table) {
            $table->dropColumn('long_description');
        });
    }
};
