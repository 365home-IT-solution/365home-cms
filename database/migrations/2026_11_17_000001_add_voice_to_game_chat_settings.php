<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Gọi thoại trực tiếp trong phòng Tiến lên / trận Caro (LiveKit, xem App\Services\GameVoiceService) — admin bật/tắt theo từng game.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('game_chat_settings', 'voice_enabled')) {
            Schema::table('game_chat_settings', function (Blueprint $table) {
                $table->boolean('voice_enabled')->default(true)->after('sticker_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('game_chat_settings', 'voice_enabled')) {
            Schema::table('game_chat_settings', fn (Blueprint $table) => $table->dropColumn('voice_enabled'));
        }
    }
};
