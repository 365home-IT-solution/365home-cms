<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// BỘ KHUNG GIỜ MẪU do super_admin soạn sẵn (khung giờ + giá gợi ý) — đối tác/admin chọn 1 bộ mẫu HOẶC
// random để tạo khung giờ cho bảng giá. Dùng chung cho mọi đối tác, không áp xuống phòng.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('time_slot_presets')) {
            return;
        }

        Schema::create('time_slot_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // [{ "start": "08:00", "end": "11:00", "over_night": false, "price": 150000 }, ...]
            $table->json('slots');
            $table->timestamps();
        });

        $now = now();

        DB::table('time_slot_presets')->insert([
            [
                'name' => 'Cơ bản 3 khung', 'note' => 'Sáng, chiều, qua đêm', 'sort_order' => 1, 'is_active' => true,
                'slots' => json_encode([
                    ['start' => '08:00', 'end' => '12:00', 'over_night' => false, 'price' => 150000],
                    ['start' => '13:00', 'end' => '18:00', 'over_night' => false, 'price' => 200000],
                    ['start' => '21:00', 'end' => '08:00', 'over_night' => true, 'price' => 350000],
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Theo giờ 5 khung', 'note' => 'Các khung 3 tiếng nối tiếp trong ngày + qua đêm', 'sort_order' => 2, 'is_active' => true,
                'slots' => json_encode([
                    ['start' => '07:00', 'end' => '10:00', 'over_night' => false, 'price' => 150000],
                    ['start' => '10:30', 'end' => '13:30', 'over_night' => false, 'price' => 170000],
                    ['start' => '14:00', 'end' => '17:00', 'over_night' => false, 'price' => 190000],
                    ['start' => '17:30', 'end' => '20:30', 'over_night' => false, 'price' => 210000],
                    ['start' => '21:00', 'end' => '07:00', 'over_night' => true, 'price' => 380000],
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'name' => 'Ngày và đêm 2 khung', 'note' => 'Cả ngày + qua đêm', 'sort_order' => 3, 'is_active' => true,
                'slots' => json_encode([
                    ['start' => '08:00', 'end' => '20:00', 'over_night' => false, 'price' => 300000],
                    ['start' => '21:00', 'end' => '08:00', 'over_night' => true, 'price' => 400000],
                ]),
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_slot_presets');
    }
};
