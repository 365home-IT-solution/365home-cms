<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // TIẾN LÊN NHIỀU NGƯỜI: phòng (mời bằng mã / ghép nhanh phòng công khai), ghế trong phòng (người hoặc bot), và danh sách người chơi
    // của từng ván (hạng + điểm thưởng riêng từng người). Ván chơi một mình với bot cũng ghi tien_len_match_players (1 dòng).
    // Xem App\Services\TienLenRoomService, App\Services\TienLenService.
    public function up(): void
    {
        if (! Schema::hasTable('tien_len_rooms')) {
            Schema::create('tien_len_rooms', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->string('code', 8)->unique();
                $table->char('host_customer_id', 36);
                $table->string('status', 10)->default('waiting'); // waiting | playing | closed
                $table->unsignedTinyInteger('max_players')->default(4);
                $table->boolean('is_public')->default(false);
                $table->char('current_match_id', 26)->nullable();
                $table->char('last_match_id', 26)->nullable();
                $table->timestamps();

                $table->index(['status', 'is_public']);
            });
        }

        if (! Schema::hasTable('tien_len_room_seats')) {
            Schema::create('tien_len_room_seats', function (Blueprint $table) {
                $table->id();
                $table->char('room_id', 26);
                $table->unsignedTinyInteger('seat');
                $table->char('customer_id', 36)->nullable(); // null = bot
                $table->boolean('is_bot')->default(false);
                $table->boolean('is_ready')->default(false);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['room_id', 'seat']);
                $table->index('customer_id');
            });
        }

        Schema::table('tien_len_matches', function (Blueprint $table) {
            if (! Schema::hasColumn('tien_len_matches', 'room_id')) {
                $table->char('room_id', 26)->nullable()->after('customer_id')->index();
            }
            if (! Schema::hasColumn('tien_len_matches', 'seats')) {
                // Ghế theo thứ tự engine: [{customer_id, name, is_bot, room_seat, auto, left, timeouts}]
                $table->json('seats')->nullable()->after('player_count');
            }
        });

        if (! Schema::hasTable('tien_len_match_players')) {
            Schema::create('tien_len_match_players', function (Blueprint $table) {
                $table->id();
                $table->char('match_id', 26);
                $table->unsignedTinyInteger('seat');
                $table->char('customer_id', 36);
                $table->string('status', 10)->default('playing'); // playing | finished | left | auto
                $table->unsignedTinyInteger('rank')->nullable();
                $table->integer('score')->nullable();
                $table->unsignedInteger('reward_points')->default(0);
                $table->timestamps();

                $table->unique(['match_id', 'customer_id']);
                $table->index(['customer_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tien_len_match_players');
        Schema::table('tien_len_matches', function (Blueprint $table) {
            foreach (['room_id', 'seats'] as $col) {
                if (Schema::hasColumn('tien_len_matches', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('tien_len_room_seats');
        Schema::dropIfExists('tien_len_rooms');
    }
};
