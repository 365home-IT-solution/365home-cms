<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Công tắc chung cho mọi trang thử mini game (/game-tester, /tien-len-tester) — bật/tắt ở CMS (Filament → Quản lý API → Thử mini game).
    public function up(): void
    {
        Schema::create('game_tester_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_tester_settings');
    }
};
