<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME TRÊN APP: khách đăng nhập là chơi được, không cần đơn hàng. Game đầu tiên là rút thẻ bài — BE trộn và giữ bộ bài
    // theo từng khách, app chỉ nhận nội dung lá đã lật. Xem App\Http\Controllers\Api\MiniGameController.
    public function up(): void
    {
        if (! Schema::hasTable('mini_games')) {
            Schema::create('mini_games', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('thumbnail')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mini_game_cards')) {
            Schema::create('mini_game_cards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mini_game_id')->constrained('mini_games')->cascadeOnDelete();
                $table->string('title');
                $table->string('subtitle')->nullable();
                // Đường dẫn trên disk public (upload từ admin, vd mini-games/cards/abc.png) hoặc URL đầy đủ
                $table->string('image');
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mini_game_sessions')) {
            Schema::create('mini_game_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('mini_game_id')->constrained('mini_games')->cascadeOnDelete();
                $table->uuid('customer_id');
                // Mảng card_id theo thứ tự đã trộn — vị trí (index) là thứ app gửi lên khi khách chọn lá
                $table->json('deck');
                // Các vị trí đã lật, theo thứ tự lật
                $table->json('drawn');
                // card_id khách đã đánh dấu "rút trúng rồi" — bị loại khỏi các lần trộn sau cho tới khi bỏ đánh dấu
                $table->json('marked');
                $table->unsignedInteger('shuffle_count')->default(0);
                $table->timestamps();

                // Mỗi khách chỉ có 1 ván cho mỗi game, trộn lại thì ghi đè
                $table->unique(['mini_game_id', 'customer_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_game_sessions');
        Schema::dropIfExists('mini_game_cards');
        Schema::dropIfExists('mini_games');
    }
};
