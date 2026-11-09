<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bộ bài Tiến lên: khách tự chọn bộ (tien_len_player_preferences), admin đặt bộ mặc định cho khách chưa chọn.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tien_len_settings', 'default_card_deck')) {
            Schema::table('tien_len_settings', function (Blueprint $table) {
                $table->string('default_card_deck', 50)->default('normal')->after('daily_points_cap');
            });
        }

        if (! Schema::hasTable('tien_len_player_preferences')) {
            Schema::create('tien_len_player_preferences', function (Blueprint $table) {
                $table->char('customer_id', 36)->primary();
                $table->string('card_deck', 50);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tien_len_player_preferences');

        if (Schema::hasColumn('tien_len_settings', 'default_card_deck')) {
            Schema::table('tien_len_settings', fn (Blueprint $table) => $table->dropColumn('default_card_deck'));
        }
    }
};
