<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CARO 3 NGƯỜI + MỜI THEO SĐT / MÃ NGƯỜI CHƠI
    // - Quân thứ 3 (t = △) đi sau X, O. Ai đầu hàng / hết giờ thì bị loại, 2 người còn lại đánh tiếp tới khi có người thắng.
    //   Ghép ngẫu nhiên theo hàng chờ riêng từng số người; lời mời 3 người mở phòng chờ, đủ 2 bạn vào thì tạo trận.
    // - Mỗi khách có 1 mã người chơi cố định để bạn bè mời đích danh; ai được báo lời mời (SĐT / mã người chơi) được lưu lại để
    //   app hiện lời mời đến kể cả khi lỡ thông báo.
    public function up(): void
    {
        Schema::table('caro_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('caro_settings', 'three_player_enabled')) {
                $table->boolean('three_player_enabled')->default(true)->after('turn_seconds');
            }
        });

        Schema::table('caro_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('caro_matches', 'player_t_id')) {
                $table->char('player_t_id', 36)->nullable()->index()->after('player_o_id');
            }
            if (! Schema::hasColumn('caro_matches', 'player_count')) {
                $table->unsignedTinyInteger('player_count')->default(2)->after('player_t_id');
            }
            // Các quân đã bị loại (đầu hàng / hết giờ) khi trận 3 người còn tiếp tục, vd ["o"]
            if (! Schema::hasColumn('caro_matches', 'eliminated')) {
                $table->json('eliminated')->nullable()->after('moves');
            }
        });

        Schema::table('caro_queue', function (Blueprint $table) {
            if (! Schema::hasColumn('caro_queue', 'player_count')) {
                $table->unsignedTinyInteger('player_count')->default(2)->after('customer_id');
                $table->index(['player_count', 'match_id', 'last_seen_at']);
            }
        });

        Schema::table('caro_invites', function (Blueprint $table) {
            if (! Schema::hasColumn('caro_invites', 'player_count')) {
                $table->unsignedTinyInteger('player_count')->default(2)->after('invitee_id');
            }
            // Những người đã nhận lời mời theo thứ tự vào phòng; đủ player_count - 1 người thì tạo trận
            if (! Schema::hasColumn('caro_invites', 'guest_ids')) {
                $table->json('guest_ids')->nullable()->after('player_count');
            }
            // Những người đã được báo lời mời (theo SĐT / mã người chơi) — lời mời đến của họ
            if (! Schema::hasColumn('caro_invites', 'notified_ids')) {
                $table->json('notified_ids')->nullable()->after('guest_ids');
                // Lời mời đến / lời mời đang gửi chỉ tìm trong các lời mời còn chờ
                $table->index(['status', 'expires_at']);
            }
        });

        Schema::table('caro_player_stats', function (Blueprint $table) {
            // Mã người chơi cố định (7 ký tự, khác độ dài mã mời 6 ký tự) — bạn bè nhập để mời đích danh
            if (! Schema::hasColumn('caro_player_stats', 'player_code')) {
                $table->string('player_code', 8)->nullable()->unique()->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('caro_player_stats', fn (Blueprint $table) => $table->dropColumn('player_code'));
        Schema::table('caro_invites', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['player_count', 'guest_ids', 'notified_ids']);
        });
        Schema::table('caro_queue', function (Blueprint $table) {
            $table->dropIndex(['player_count', 'match_id', 'last_seen_at']);
            $table->dropColumn('player_count');
        });
        Schema::table('caro_matches', fn (Blueprint $table) => $table->dropColumn(['player_count', 'player_t_id', 'eliminated']));
        Schema::table('caro_settings', fn (Blueprint $table) => $table->dropColumn('three_player_enabled'));
    }
};
