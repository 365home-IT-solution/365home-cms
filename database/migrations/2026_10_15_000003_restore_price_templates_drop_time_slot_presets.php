<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Khôi phục "bảng giá mẫu" (price_templates) làm NGUỒN khung giờ + giá để chọn khi tạo bảng giá
// (price_boards) — dùng mẫu = tạo bảng giá, không ghi thẳng xuống phòng. Bỏ bảng "bộ khung giờ mẫu"
// (time_slot_presets) vì mẫu giá đã gồm cả khung giờ + giá.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('price_templates')) {
            Schema::create('price_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('partner_id')->nullable();
                $table->foreignId('source_template_id')->nullable()->constrained('price_templates')->nullOnDelete();
                $table->string('name');
                $table->text('note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->index(['partner_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('price_template_slots')) {
            Schema::create('price_template_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_template_id')->constrained('price_templates')->cascadeOnDelete();
                $table->foreignId('timeslot_id')->constrained('time_slots')->restrictOnDelete();
                $table->unsignedBigInteger('price')->nullable();
                $table->string('checkin', 5)->nullable();
                $table->string('checkout', 5)->nullable();
                $table->boolean('over_night')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['price_template_id', 'timeslot_id']);
            });
        }

        $this->seedSystemTemplates();

        Schema::dropIfExists('time_slot_presets');
    }

    /** Vài bảng giá mẫu hệ thống để đối tác có sẵn lựa chọn (dựng từ khung giờ hệ thống hiện có). */
    private function seedSystemTemplates(): void
    {
        if (DB::table('price_templates')->whereNull('partner_id')->exists()) {
            return;
        }

        $slots = DB::table('time_slots')
            ->whereNull('partner_id')
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->orderBy('start_time')
            ->get()
            // mỗi khung giờ (cùng giờ bắt đầu–kết thúc) chỉ 1 dòng
            ->unique(fn ($t) => substr((string) $t->start_time, 0, 5) . '-' . substr((string) $t->end_time, 0, 5))
            ->values();

        $day   = $slots->filter(fn ($t) => ! $t->over_night)->values();
        $night = $slots->filter(fn ($t) => (bool) $t->over_night)->values();

        if ($day->count() < 2) {
            return;
        }

        $now = now();
        $make = function (string $name, string $note, int $order, $chosen, callable $price) use ($now) {
            $id = DB::table('price_templates')->insertGetId([
                'partner_id' => null, 'name' => $name, 'note' => $note, 'is_active' => true,
                'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($chosen->values() as $i => $t) {
                DB::table('price_template_slots')->insert([
                    'price_template_id' => $id, 'timeslot_id' => $t->id, 'price' => $price($t),
                    'over_night' => (bool) $t->over_night, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        };

        $hours = fn ($t) => max(1, ((strtotime($t->end_time) - strtotime($t->start_time) + 86400) % 86400) / 3600);
        $dayPrice = fn ($t) => (int) max(100000, min(300000, round($hours($t) * 55000, -4)));

        $make('Giá ngày thường', 'Các khung trong ngày + qua đêm', 1, $day->take(5)->concat($night->take(1)),
            fn ($t) => $t->over_night ? 350000 : $dayPrice($t));
        $make('Giá cuối tuần', 'Cao hơn ngày thường 20%', 2, $day->take(5)->concat($night->take(1)),
            fn ($t) => (int) round(($t->over_night ? 350000 : $dayPrice($t)) * 1.2, -4));
    }

    public function down(): void
    {
        Schema::dropIfExists('price_template_slots');
        Schema::dropIfExists('price_templates');
    }
};
