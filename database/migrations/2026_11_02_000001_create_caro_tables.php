<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // MINI GAME CARO TRỰC TUYẾN (chỉ người với người): ghép ngẫu nhiên 2 khách đang chờ, hoặc khách mời bạn vào đấu (theo SĐT hoặc link/mã).
    // Tiến độ thưởng chạy theo vòng mốc (vd thắng 2 → 10K, 4 → 20K, 5 → bốc voucher lớn rồi về 0), cộng thêm trận thưởng khi chơi chuỗi ngày
    // và khi mời bạn mới. Voucher hạn 7 ngày, gần hết hạn thì nhắc khách quay lại đặt phòng. Xem App\Services\CaroService.
    public function up(): void
    {
        if (! Schema::hasTable('caro_settings')) {
            Schema::create('caro_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('is_enabled')->default(false);
                $table->unsignedTinyInteger('board_size')->default(15);
                $table->unsignedTinyInteger('win_length')->default(5);
                // Luật chặn 2 đầu: đủ 5 quân mà cả 2 đầu bị quân đối thủ chặn thì không tính thắng
                $table->boolean('block_two_ends')->default(false);
                $table->unsignedSmallInteger('turn_seconds')->default(30);
                // Chờ bấy nhiêu giây không ghép được ai thì app gợi ý mời bạn vào đấu
                $table->unsignedSmallInteger('match_wait_seconds')->default(20);
                $table->boolean('invite_enabled')->default(true);
                $table->unsignedSmallInteger('invite_expire_minutes')->default(10);
                $table->boolean('invite_wins_count')->default(true);
                $table->string('invite_share_url_base')->default('https://365home.vn/caro/');
                // Mốc thưởng trong 1 vòng: [{wins, mode: fixed|random, prize_id}] — mốc lớn nhất là độ dài vòng, đạt xong tiến độ về 0
                $table->json('milestones')->nullable();
                // Chơi đủ bấy nhiêu ngày liên tiếp (mỗi ngày ≥ 1 trận xong) thì được cộng 1 trận vào tiến độ; 0 = tắt
                $table->unsignedTinyInteger('streak_days')->default(3);
                // Bạn được mời chơi xong trận đầu tiên → cả người mời và bạn được cộng 1 trận
                $table->boolean('invite_bonus_enabled')->default(true);
                $table->unsignedSmallInteger('invite_bonus_daily_cap')->nullable()->default(3);
                // Nhắc khách khi voucher caro chưa dùng sắp hết hạn
                $table->boolean('reminder_enabled')->default(true);
                $table->unsignedSmallInteger('reminder_hours_before')->default(24);
                // Tối đa bấy nhiêu mã caro trong 1 đơn (mã không độc quyền vẫn cộng dồn được với nhau); null = không giới hạn
                $table->unsignedTinyInteger('max_caro_coupons_per_order')->nullable()->default(3);
                // Chống thông đồng: thắng do đối thủ đầu hàng/hết giờ quá sớm, thắng quá nhiều/ngày hoặc thắng mãi 1 người thì không tính
                $table->unsignedSmallInteger('min_moves_to_count')->default(10);
                // Ít trận được tính mỗi ngày để khách phải quay lại nhiều ngày mới đủ vòng
                $table->unsignedSmallInteger('daily_counted_wins_cap')->nullable()->default(3);
                $table->unsignedSmallInteger('same_opponent_daily_cap')->nullable()->default(2);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_prizes')) {
            Schema::create('caro_prizes', function (Blueprint $table) {
                $table->id();
                $table->string('label');
                $table->string('coupon_type', 12)->default('fixed'); // fixed | percentage
                $table->decimal('coupon_value', 12, 2);
                $table->decimal('min_order_value', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                // Voucher caro mặc định hết hạn sau 7 ngày kể từ lúc nhận — hạn ngắn để khách quay lại đặt phòng sớm
                $table->unsignedSmallInteger('validity_days')->default(7);
                $table->boolean('is_exclusive')->default(false);
                $table->unsignedInteger('weight')->default(1);
                $table->unsignedInteger('daily_limit')->nullable();
                $table->unsignedInteger('total_limit')->nullable();
                $table->unsignedInteger('awarded_count')->default(0);
                $table->boolean('is_fallback')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            // Bộ giải mặc định — chi nhánh áp dụng chọn ở admin (bảng categorizables), để trống = mọi chi nhánh
            $now = now();
            DB::table('caro_prizes')->insert([
                ['label' => 'Voucher 10K', 'coupon_value' => 10_000, 'min_order_value' => 220_000, 'is_exclusive' => false, 'weight' => 50, 'is_fallback' => true, 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Voucher 20K', 'coupon_value' => 20_000, 'min_order_value' => 220_000, 'is_exclusive' => false, 'weight' => 30, 'is_fallback' => false, 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Voucher 50K', 'coupon_value' => 50_000, 'min_order_value' => 250_000, 'is_exclusive' => true, 'weight' => 15, 'is_fallback' => false, 'created_at' => $now, 'updated_at' => $now],
                ['label' => 'Voucher 100K', 'coupon_value' => 100_000, 'min_order_value' => 250_000, 'is_exclusive' => true, 'weight' => 5, 'is_fallback' => false, 'created_at' => $now, 'updated_at' => $now],
            ]);

            // Vòng mốc mặc định: thắng 2 → 10K, thắng 4 → 20K (2 mã nhỏ cộng dồn được), thắng 5 → bốc ngẫu nhiên theo trọng số
            $prizeIds = DB::table('caro_prizes')->pluck('id', 'label');
            DB::table('caro_settings')->insert([
                'milestones' => json_encode([
                    ['wins' => 2, 'mode' => 'fixed', 'prize_id' => $prizeIds['Voucher 10K']],
                    ['wins' => 4, 'mode' => 'fixed', 'prize_id' => $prizeIds['Voucher 20K']],
                    ['wins' => 5, 'mode' => 'random', 'prize_id' => null],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('caro_matches')) {
            Schema::create('caro_matches', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->char('player_x_id', 36)->index();
                $table->char('player_o_id', 36)->index();
                $table->string('source', 10)->default('queue'); // queue (ghép ngẫu nhiên) | invite (mời bạn)
                // Chụp luật lúc tạo trận — admin đổi cài đặt không ảnh hưởng trận đang chơi
                $table->unsignedTinyInteger('board_size');
                $table->unsignedTinyInteger('win_length');
                $table->boolean('block_two_ends')->default(false);
                $table->unsignedSmallInteger('turn_seconds');
                // [[x, y], ...] theo thứ tự đi; X đi trước nên lượt chẵn là X
                $table->json('moves');
                $table->string('status', 10)->default('playing')->index(); // playing | finished
                $table->string('winner', 5)->nullable(); // x | o | draw
                $table->string('end_reason', 10)->nullable(); // line | resign | timeout | draw
                $table->json('winning_line')->nullable();
                $table->char('winner_customer_id', 36)->nullable();
                $table->boolean('counted_for_reward')->default(false);
                $table->timestamp('turn_deadline_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_queue')) {
            Schema::create('caro_queue', function (Blueprint $table) {
                $table->char('customer_id', 36)->primary();
                // Đã được ghép thì lưu trận ở đây cho tới khi khách hỏi lại hàng chờ
                $table->ulid('match_id')->nullable();
                $table->timestamp('last_seen_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_invites')) {
            Schema::create('caro_invites', function (Blueprint $table) {
                $table->id();
                // Mã gửi cho bạn (kèm link share_url_base + mã); bạn mở link / nhập mã để vào trận
                $table->string('code', 8)->unique();
                $table->char('inviter_id', 36)->index();
                // Mời đích danh theo SĐT thì chỉ người này nhận được; null = ai có mã cũng nhận được
                $table->char('invitee_id', 36)->nullable()->index();
                $table->string('status', 10)->default('pending'); // pending | accepted | declined | cancelled | expired
                $table->ulid('match_id')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_player_stats')) {
            Schema::create('caro_player_stats', function (Blueprint $table) {
                $table->char('customer_id', 36)->primary();
                $table->unsignedInteger('wins')->default(0);
                $table->unsignedInteger('losses')->default(0);
                $table->unsignedInteger('draws')->default(0);
                // Tiến độ trong vòng mốc hiện tại (trận thắng được tính + trận thưởng) — hết vòng thì về 0
                $table->unsignedInteger('pending_wins')->default(0);
                // Chuỗi ngày chơi liên tiếp (mỗi ngày có ≥ 1 trận xong)
                $table->unsignedSmallInteger('streak_days')->default(0);
                $table->date('last_played_on')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_rewards')) {
            Schema::create('caro_rewards', function (Blueprint $table) {
                $table->id();
                $table->char('customer_id', 36)->index();
                $table->ulid('match_id')->nullable();
                $table->unsignedBigInteger('prize_id')->nullable();
                $table->string('prize_label');
                $table->unsignedBigInteger('coupon_id')->nullable();
                // Mốc đã đạt (số trận trong vòng) khi nhận voucher này
                $table->unsignedTinyInteger('milestone_wins')->nullable();
                $table->timestamp('expiry_reminded_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('caro_bonuses')) {
            // Trận thưởng cộng vào tiến độ ngoài trận thắng: streak (chơi chuỗi ngày) | invite (mời bạn mới chơi trận đầu)
            Schema::create('caro_bonuses', function (Blueprint $table) {
                $table->id();
                $table->char('customer_id', 36)->index();
                $table->string('type', 10);
                $table->ulid('match_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caro_bonuses');
        Schema::dropIfExists('caro_rewards');
        Schema::dropIfExists('caro_player_stats');
        Schema::dropIfExists('caro_invites');
        Schema::dropIfExists('caro_queue');
        Schema::dropIfExists('caro_matches');
        Schema::dropIfExists('caro_prizes');
        Schema::dropIfExists('caro_settings');
    }
};
