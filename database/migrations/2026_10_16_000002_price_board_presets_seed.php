<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Bảng giá dựng sẵn (preset) — bảng ở trạng thái mẫu (is_template, partner_id NULL). Khung giờ của preset
// lưu theo GIỜ (slot_times) chứ không theo id: dùng preset thì tìm khung trùng giờ của đối tác / hệ thống,
// chưa có thì tạo khung riêng của đối tác. Giai đoạn 1 chỉ có standard + budget.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_board_sheets', function (Blueprint $table) {
            if (! Schema::hasColumn('price_board_sheets', 'slot_times')) {
                // [{ "start": "07:30", "end": "10:30", "over_night": false, "price": 160000 }] — chỉ preset dùng.
                $table->json('slot_times')->nullable()->after('adjustment_timeslot_ids');
            }
        });

        if (DB::table('price_boards')->where('is_template', true)->exists()) {
            return;
        }

        $now = now();

        $slots = fn (array $prices, int $overnight) => [
            ['start' => '07:30', 'end' => '10:30', 'over_night' => false, 'price' => $prices[0]],
            ['start' => '10:45', 'end' => '13:45', 'over_night' => false, 'price' => $prices[1]],
            ['start' => '14:00', 'end' => '17:00', 'over_night' => false, 'price' => $prices[2]],
            ['start' => '17:15', 'end' => '20:15', 'over_night' => false, 'price' => $prices[3]],
            ['start' => '21:30', 'end' => '07:30', 'over_night' => true, 'price' => $overnight],
        ];

        $vip = fn (array $slotsDef) => array_map(
            fn ($s) => $s['price'] === null ? $s : array_merge($s, ['price' => (int) (round($s['price'] * 1.4 / 10000) * 10000)]),
            $slotsDef
        );

        $defs = [
            'standard' => [
                'name' => 'Giá thường', 'sort' => 1,
                'description' => 'Bảng nền cho mọi phòng: giá theo khung giờ, VIP và theo ngày.',
                'tags' => ['nền'],
                'hourly' => $slots([160000, 150000, 170000, 190000], 350000),
                'daily'  => 500000,
            ],
            'budget' => [
                'name' => 'Giá tiết kiệm', 'sort' => 2,
                'description' => 'Bảng giá cho phòng bình dân: khung 3 tiếng giá thấp hơn.',
                'tags' => ['bình dân'],
                'hourly' => $slots([120000, 130000, 140000, 150000], 280000),
                'daily'  => 380000,
            ],
        ];

        foreach ($defs as $code => $def) {
            $boardId = DB::table('price_boards')->insertGetId([
                'partner_id' => null, 'category_id' => null, 'name' => $def['name'], 'note' => null,
                'is_default' => false, 'is_active' => false, 'pricing_mode' => 'override',
                'scope' => 'partner', 'is_template' => true, 'code' => $code,
                'description' => $def['description'], 'tags' => json_encode($def['tags'], JSON_UNESCAPED_UNICODE),
                'default_scope' => 'partner', 'requires_dates' => false, 'sort_order' => $def['sort'],
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $common = [
                'full_booking_discount' => '20%',
                'bulk_discount_rules'   => json_encode([['slots' => 2, 'discount' => 5], ['slots' => 3, 'discount' => 10]]),
                'room_config'           => json_encode(['max_free_guests' => 2, 'extra_guest_fee' => 50000]),
                'fields_set'            => json_encode(['full_booking_discount', 'bulk_discount_rules', 'room_config']),
            ];

            $sheets = [
                ['name' => 'Tiêu chuẩn theo giờ', 'room_style' => 1, 'is_default' => true, 'slot_times' => $def['hourly']] + $common,
                ['name' => 'VIP theo giờ', 'room_style' => 1, 'is_default' => false, 'slot_times' => $vip($def['hourly'])] + $common,
                [
                    'name' => 'Tiêu chuẩn theo ngày', 'room_style' => 2, 'is_default' => true, 'slot_times' => null,
                    'price' => $def['daily'], 'default_checkin' => '14:00', 'default_checkout' => '12:00',
                    'deposit_min_nights' => 2, 'deposit_multi_night' => 50, 'deposit_1_night' => 100,
                    'fields_set' => json_encode([]),
                ],
            ];

            foreach ($sheets as $i => $sheet) {
                DB::table('price_board_sheets')->insert(array_merge([
                    'price_board_id' => $boardId, 'pricing_mode' => 'override', 'sort_order' => $i,
                    'created_at' => $now, 'updated_at' => $now,
                ], array_merge($sheet, [
                    'slot_times' => $sheet['slot_times'] === null ? null : json_encode($sheet['slot_times']),
                ])));
            }
        }
    }

    public function down(): void
    {
        DB::table('price_boards')->where('is_template', true)->whereIn('code', ['standard', 'budget'])->delete();

        Schema::table('price_board_sheets', function (Blueprint $table) {
            if (Schema::hasColumn('price_board_sheets', 'slot_times')) {
                $table->dropColumn('slot_times');
            }
        });
    }
};
