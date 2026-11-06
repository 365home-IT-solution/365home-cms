<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME TIẾN LÊN MIỀN NAM (khách chơi với 1–3 bot, BE giữ toàn bộ bài) + ĐIỂM GAME (thắng ván được điểm, đổi điểm ra voucher).
    // Engine luật: App\Games\TienLen\*; luồng ván + thưởng điểm: App\Services\TienLenService; ví điểm: App\Services\GamePointService.
    public function up(): void
    {
        if (! Schema::hasTable('tien_len_settings')) {
            Schema::create('tien_len_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(false);
                $table->unsignedSmallInteger('turn_seconds')->default(20);
                // Điểm thưởng theo hạng của khách trong ván (bàn 2 người: nhất/bét; 3 người: nhất/nhì/bét)
                $table->unsignedInteger('points_first')->default(10);
                $table->unsignedInteger('points_second')->default(5);
                $table->unsignedInteger('points_third')->default(2);
                $table->unsignedInteger('points_last')->default(0);
                // Cộng thêm mỗi lần khách chặt heo / chặt hàng
                $table->unsignedInteger('chop_bonus_points')->default(1);
                // Tối đa điểm khách nhận từ Tiến lên mỗi ngày (0 = không giới hạn)
                $table->unsignedInteger('daily_points_cap')->default(100);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tien_len_matches')) {
            Schema::create('tien_len_matches', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('customer_id', 36);
                $table->unsignedTinyInteger('player_count');
                $table->string('status', 12)->default('playing'); // playing | finished | abandoned
                // Phiên bản trạng thái — app gửi kèm mỗi nước đi để chặn bấm trùng / gửi nước cũ
                $table->unsignedInteger('seq')->default(0);
                $table->unsignedInteger('event_count')->default(0);
                $table->longText('state');
                $table->boolean('is_first_match')->default(false);
                $table->string('instant_win', 20)->nullable();
                $table->unsignedTinyInteger('human_rank')->nullable();
                $table->integer('human_score')->nullable();
                $table->unsignedInteger('reward_points')->default(0);
                $table->timestamp('turn_deadline')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'status']);
                $table->index(['status', 'updated_at']);
            });
        }

        if (! Schema::hasTable('tien_len_moves')) {
            Schema::create('tien_len_moves', function (Blueprint $table) {
                $table->id();
                $table->char('match_id', 26)->index();
                $table->unsignedInteger('event_no');
                $table->unsignedTinyInteger('seat')->nullable();
                $table->string('type', 20); // deal | play | pass | decline_chop | round_end | chop_settled | finish | cong | instant_win | forfeit | game_end
                $table->json('cards')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('game_point_wallets')) {
            Schema::create('game_point_wallets', function (Blueprint $table) {
                $table->char('customer_id', 36)->primary();
                $table->unsignedInteger('balance')->default(0);
                $table->unsignedInteger('total_earned')->default(0);
                $table->unsignedInteger('total_redeemed')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('game_point_rewards')) {
            Schema::create('game_point_rewards', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('points_cost');
                $table->string('coupon_type', 12); // fixed | percentage
                $table->decimal('coupon_value', 12, 2);
                $table->decimal('min_order_value', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->unsignedSmallInteger('validity_days')->default(30);
                $table->unsignedInteger('stock')->nullable();              // null = không giới hạn
                $table->unsignedInteger('per_customer_limit')->nullable(); // null = không giới hạn
                $table->unsignedInteger('redeemed_count')->default(0);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('game_point_transactions')) {
            Schema::create('game_point_transactions', function (Blueprint $table) {
                $table->id();
                $table->char('customer_id', 36);
                $table->integer('amount');                  // + cộng, − trừ
                $table->unsignedInteger('balance_after');
                $table->string('type', 10);                 // earn | redeem | adjust
                $table->string('source', 20);               // tien_len | reward | admin
                $table->string('reference_id', 36)->nullable(); // id ván Tiến lên
                $table->unsignedBigInteger('reward_id')->nullable();
                $table->unsignedBigInteger('coupon_id')->nullable();
                $table->string('note')->nullable();
                $table->char('created_by', 36)->nullable(); // admin cộng/trừ tay (users.id là uuid)
                $table->timestamps();

                $table->index(['customer_id', 'created_at']);
                $table->index(['source', 'created_at']);
                // 1 ván chỉ thưởng 1 lần
                $table->unique(['source', 'reference_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_point_transactions');
        Schema::dropIfExists('game_point_rewards');
        Schema::dropIfExists('game_point_wallets');
        Schema::dropIfExists('tien_len_moves');
        Schema::dropIfExists('tien_len_matches');
        Schema::dropIfExists('tien_len_settings');
    }
};
