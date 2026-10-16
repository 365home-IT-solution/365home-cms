<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng giá v2 GIAI ĐOẠN 2: priority + days_of_week trên bảng, và các preset cần chồng lên bảng nền
 * (weekend, holiday, low_hours, opening). `phase` = giai đoạn tối thiểu để preset hiện ra (cờ
 * config('price_boards.phase2')). Cột luôn có sẵn; chỉ được dùng khi bật cờ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_boards', function (Blueprint $table) {
            if (! Schema::hasColumn('price_boards', 'priority')) {
                $table->unsignedTinyInteger('priority')->default(0)->after('sort_order');
                $table->json('days_of_week')->nullable()->after('priority');
                $table->unsignedTinyInteger('phase')->default(1)->after('days_of_week');
            }
        });

        $exists = fn (string $code) => DB::table('price_boards')->where('is_template', true)->where('code', $code)->exists();
        $now    = now();

        $make = function (string $code, array $board, array $sheets) use ($now) {
            $boardId = DB::table('price_boards')->insertGetId(array_merge([
                'partner_id' => null, 'category_id' => null, 'note' => null, 'is_default' => false, 'is_active' => false,
                'pricing_mode' => 'adjustment', 'is_template' => true, 'code' => $code, 'phase' => 2,
                'created_at' => $now, 'updated_at' => $now,
            ], $board));

            foreach ($sheets as $i => $sheet) {
                DB::table('price_board_sheets')->insert(array_merge([
                    'price_board_id' => $boardId, 'pricing_mode' => 'adjustment', 'room_style' => null,
                    'is_default' => true, 'sort_order' => $i, 'fields_set' => json_encode([]),
                    'created_at' => $now, 'updated_at' => $now,
                ], array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $sheet)));
            }
        };

        if (! $exists('weekend')) {
            $make('weekend', [
                'name' => 'Cuối tuần', 'description' => 'Tăng 15% cho mọi phòng vào thứ 6 và thứ 7 (ban ngày và đêm).',
                'tags' => json_encode(['theo thứ'], JSON_UNESCAPED_UNICODE), 'scope' => 'partner', 'default_scope' => 'partner',
                'requires_dates' => false, 'sort_order' => 3, 'priority' => 10, 'days_of_week' => json_encode([5, 6]),
                'adjustment_type' => 'percentage', 'adjustment_value' => 15,
            ], [
                ['name' => 'Cuối tuần +15%', 'adjustment_type' => 'percentage', 'adjustment_value' => 15],
            ]);
        }

        if (! $exists('holiday')) {
            $make('holiday', [
                'name' => 'Lễ Tết', 'description' => 'Tăng giá dịp lễ Tết: theo giờ +30%, theo ngày +40%. Bắt buộc chọn ngày.',
                'tags' => json_encode(['lễ'], JSON_UNESCAPED_UNICODE), 'scope' => 'partner', 'default_scope' => 'partner',
                'requires_dates' => true, 'sort_order' => 4, 'priority' => 30, 'days_of_week' => null,
            ], [
                ['name' => 'Theo giờ +30%', 'room_style' => 1, 'adjustment_type' => 'percentage', 'adjustment_value' => 30],
                ['name' => 'Theo ngày +40%', 'room_style' => 2, 'adjustment_type' => 'percentage', 'adjustment_value' => 40],
            ]);
        }

        if (! $exists('low_hours')) {
            $make('low_hours', [
                'name' => 'Giờ thấp điểm', 'description' => 'Giảm 20% chỉ các khung trưa 10:45–13:45 và 14:00–17:00 để kéo khách giờ vắng.',
                'tags' => json_encode(['giờ vắng'], JSON_UNESCAPED_UNICODE), 'scope' => 'partner', 'default_scope' => 'partner',
                'requires_dates' => false, 'sort_order' => 5, 'priority' => 5, 'days_of_week' => null,
            ], [
                [
                    'name' => 'Giảm khung trưa -20%', 'room_style' => 1, 'adjustment_type' => 'percentage', 'adjustment_value' => -20,
                    // Khung cần chỉnh theo GIỜ — BE quy thành adjustment_timeslot_ids khi dùng preset.
                    'slot_times' => [
                        ['start' => '10:45', 'end' => '13:45', 'over_night' => false, 'price' => 0],
                        ['start' => '14:00', 'end' => '17:00', 'over_night' => false, 'price' => 0],
                    ],
                ],
            ]);
        }

        if (! $exists('opening')) {
            $make('opening', [
                'name' => 'Khai trương', 'description' => 'Giảm 15% mọi phòng của chi nhánh mới trong vài tuần đầu. Bắt buộc chọn ngày.',
                'tags' => json_encode(['khuyến mãi'], JSON_UNESCAPED_UNICODE), 'scope' => 'branches', 'default_scope' => 'branches',
                'requires_dates' => true, 'sort_order' => 6, 'priority' => 20, 'days_of_week' => null,
            ], [
                ['name' => 'Khai trương -15%', 'adjustment_type' => 'percentage', 'adjustment_value' => -15],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('price_boards')->where('is_template', true)->whereIn('code', ['weekend', 'holiday', 'low_hours', 'opening'])->delete();

        Schema::table('price_boards', function (Blueprint $table) {
            if (Schema::hasColumn('price_boards', 'priority')) {
                $table->dropColumn(['priority', 'days_of_week', 'phase']);
            }
        });
    }
};
