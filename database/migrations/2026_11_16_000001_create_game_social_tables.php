<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Âm thanh, sticker và chat trong trận cho mini game Tiến lên + Caro (xem App\Services\GameSoundPackService, GameStickerService, GameChatService).
// Tin nhắn chat KHÔNG lưu DB — chỉ phát realtime và giữ tạm vài chục tin gần nhất trong cache.
return new class extends Migration
{
    public function up(): void
    {
        // Bộ âm thanh theo từng game; sounds = { "deal": "storage/games/sounds/…mp3", … }, sự kiện thiếu file ⇒ dùng file của bộ mặc định.
        if (! Schema::hasTable('game_sound_packs')) {
            Schema::create('game_sound_packs', function (Blueprint $table) {
                $table->id();
                $table->string('game', 20);
                $table->string('key', 50);
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->integer('sort_order')->default(0);
                $table->string('cover_image')->nullable();
                $table->json('sounds')->nullable();
                $table->timestamps();

                $table->unique(['game', 'key']);
            });
        }

        // Bộ sticker dùng chung — games = ["tien_len", "caro"] là game nào được dùng bộ này.
        if (! Schema::hasTable('game_sticker_packs')) {
            Schema::create('game_sticker_packs', function (Blueprint $table) {
                $table->id();
                $table->string('key', 50)->unique();
                $table->string('name');
                $table->string('icon_image')->nullable();
                $table->json('games')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('game_stickers')) {
            Schema::create('game_stickers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pack_id')->constrained('game_sticker_packs')->cascadeOnDelete();
                $table->string('image');
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Tuỳ chọn âm thanh của khách theo từng game.
        if (! Schema::hasTable('game_player_preferences')) {
            Schema::create('game_player_preferences', function (Blueprint $table) {
                $table->char('customer_id', 36);
                $table->string('game', 20);
                $table->string('sound_pack', 50)->nullable();
                $table->boolean('sfx_enabled')->default(true);
                $table->boolean('music_enabled')->default(true);
                $table->unsignedTinyInteger('sfx_volume')->default(100);
                $table->unsignedTinyInteger('music_volume')->default(70);
                $table->timestamps();

                $table->primary(['customer_id', 'game']);
            });
        }

        // Cấu hình chat / sticker theo từng game (1 dòng / game).
        if (! Schema::hasTable('game_chat_settings')) {
            Schema::create('game_chat_settings', function (Blueprint $table) {
                $table->string('game', 20)->primary();
                $table->boolean('chat_enabled')->default(true);
                $table->boolean('sticker_enabled')->default(true);
                $table->unsignedSmallInteger('max_length')->default(200);
                $table->unsignedSmallInteger('message_cooldown_seconds')->default(1);
                $table->unsignedSmallInteger('sticker_cooldown_seconds')->default(2);
                $table->unsignedSmallInteger('history_size')->default(30);
                $table->json('banned_words')->nullable();
                $table->timestamps();
            });
        }

        foreach (['tien_len', 'caro'] as $game) {
            if (! DB::table('game_chat_settings')->where('game', $game)->exists()) {
                DB::table('game_chat_settings')->insert(['game' => $game, 'banned_words' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_chat_settings');
        Schema::dropIfExists('game_player_preferences');
        Schema::dropIfExists('game_stickers');
        Schema::dropIfExists('game_sticker_packs');
        Schema::dropIfExists('game_sound_packs');
    }
};
