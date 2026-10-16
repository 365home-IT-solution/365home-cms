<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BẢNG GIÁ v2 (giai đoạn 1): một bảng gồm nhiều BỘ GIÁ (sheets), áp theo phạm vi phòng / chi nhánh /
 * toàn đối tác, kèm bảng giá dựng sẵn (preset = bảng ở trạng thái mẫu, partner_id NULL).
 *
 * Các bảng giá v1 (mỗi bảng 1 chi nhánh, items[] theo phòng) được chuyển thành scope = rooms: các phòng
 * có cùng khung giờ + giá + ưu đãi gộp thành MỘT bộ giá. price_board_items vẫn là dữ liệu được sinh ra
 * (động cơ ghi giá / khôi phục / job đêm đọc nó như cũ), chỉ thêm sheet_id để truy vết.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_boards', function (Blueprint $table) {
            if (! Schema::hasColumn('price_boards', 'scope')) {
                $table->string('scope', 20)->default('rooms')->after('category_id');
                $table->string('source_preset', 50)->nullable()->after('scope');
                $table->boolean('is_template')->default(false)->after('source_preset');
                $table->string('code', 50)->nullable()->after('is_template');
                $table->text('description')->nullable()->after('code');
                $table->json('tags')->nullable()->after('description');
                $table->string('default_scope', 20)->nullable()->after('tags');
                $table->boolean('requires_dates')->default(false)->after('default_scope');
                $table->unsignedInteger('sort_order')->default(0)->after('requires_dates');

                $table->index(['is_template', 'code']);
            }
        });

        if (! Schema::hasTable('price_board_branches')) {
            Schema::create('price_board_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_board_id')->constrained('price_boards')->cascadeOnDelete();
                $table->unsignedBigInteger('category_id');
                $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
                $table->unique(['price_board_id', 'category_id']);
            });
        }

        // Phòng khai báo ở cấp bảng (scope = rooms) — hợp với phòng gán đích danh ở từng bộ giá.
        if (! Schema::hasTable('price_board_rooms')) {
            Schema::create('price_board_rooms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_board_id')->constrained('price_boards')->cascadeOnDelete();
                $table->char('product_id', 36);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unique(['price_board_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('price_board_sheets')) {
            Schema::create('price_board_sheets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_board_id')->constrained('price_boards')->cascadeOnDelete();
                $table->string('name');
                $table->string('pricing_mode', 20)->default('override');
                $table->unsignedTinyInteger('room_style')->nullable();
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);

                // Theo ngày (styles = 2)
                $table->decimal('price', 15, 2)->nullable();
                $table->string('default_checkin', 5)->nullable();
                $table->string('default_checkout', 5)->nullable();
                $table->unsignedTinyInteger('deposit_1_night')->nullable();
                $table->unsignedTinyInteger('deposit_multi_night')->nullable();
                $table->unsignedTinyInteger('deposit_min_nights')->nullable();

                // Ưu đãi — fields_set = trường nào được gửi (không gửi = giữ giá trị hiện tại của phòng).
                $table->string('full_booking_discount', 50)->nullable();
                $table->json('bulk_discount_rules')->nullable();
                $table->json('room_config')->nullable();
                $table->json('fields_set')->nullable();

                // adjustment
                $table->string('adjustment_type', 20)->nullable();
                $table->decimal('adjustment_value', 10, 2)->nullable();
                $table->json('adjustment_timeslot_ids')->nullable();

                $table->timestamps();

                $table->index(['price_board_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('price_board_sheet_slots')) {
            Schema::create('price_board_sheet_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sheet_id')->constrained('price_board_sheets')->cascadeOnDelete();
                $table->unsignedBigInteger('timeslot_id');
                $table->foreign('timeslot_id')->references('id')->on('time_slots')->cascadeOnDelete();
                $table->unsignedBigInteger('price')->nullable();
                $table->boolean('over_night')->default(false);
                $table->string('status', 30)->default('available');
                $table->unsignedInteger('sort_order')->default(0);
                $table->unique(['sheet_id', 'timeslot_id']);
            });
        }

        if (! Schema::hasTable('price_board_sheet_products')) {
            Schema::create('price_board_sheet_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sheet_id')->constrained('price_board_sheets')->cascadeOnDelete();
                $table->char('product_id', 36);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unique(['sheet_id', 'product_id']);
            });
        }

        Schema::table('price_board_items', function (Blueprint $table) {
            if (! Schema::hasColumn('price_board_items', 'sheet_id')) {
                $table->unsignedBigInteger('sheet_id')->nullable()->after('product_id');
                $table->index('sheet_id');
            }
        });

        $this->convertV1Boards();
    }

    public function down(): void
    {
        Schema::table('price_board_items', function (Blueprint $table) {
            if (Schema::hasColumn('price_board_items', 'sheet_id')) {
                $table->dropIndex(['sheet_id']);
                $table->dropColumn('sheet_id');
            }
        });

        Schema::dropIfExists('price_board_sheet_products');
        Schema::dropIfExists('price_board_sheet_slots');
        Schema::dropIfExists('price_board_sheets');
        Schema::dropIfExists('price_board_rooms');
        Schema::dropIfExists('price_board_branches');

        Schema::table('price_boards', function (Blueprint $table) {
            if (Schema::hasColumn('price_boards', 'scope')) {
                $table->dropIndex(['is_template', 'code']);
                $table->dropColumn(['scope', 'source_preset', 'is_template', 'code', 'description', 'tags', 'default_scope', 'requires_dates', 'sort_order']);
            }
        });
    }

    /** v1 → v2: mỗi bảng thành scope = rooms; phòng cùng khung giờ + giá + ưu đãi gộp thành 1 bộ giá. */
    private function convertV1Boards(): void
    {
        $now = now();

        DB::table('price_boards')
            ->where('is_default', false)
            ->where('is_template', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('price_board_sheets')->whereColumn('price_board_sheets.price_board_id', 'price_boards.id'))
            ->orderBy('id')
            ->each(function ($board) use ($now) {
                $items = DB::table('price_board_items')
                    ->join('products', 'products.id', '=', 'price_board_items.product_id')
                    ->where('price_board_items.price_board_id', $board->id)
                    ->get(['price_board_items.*', 'products.styles as room_style']);

                if ($items->isEmpty()) {
                    return;
                }

                if (($board->pricing_mode ?? 'override') === 'adjustment') {
                    $sheetId = DB::table('price_board_sheets')->insertGetId([
                        'price_board_id' => $board->id, 'name' => 'Điều chỉnh giá', 'pricing_mode' => 'adjustment',
                        'room_style' => null, 'is_default' => false, 'sort_order' => 0,
                        'adjustment_type' => $board->adjustment_type, 'adjustment_value' => $board->adjustment_value,
                        'fields_set' => json_encode([]), 'created_at' => $now, 'updated_at' => $now,
                    ]);

                    foreach ($items as $item) {
                        DB::table('price_board_sheet_products')->insert(['sheet_id' => $sheetId, 'product_id' => $item->product_id]);
                    }

                    DB::table('price_board_items')->where('price_board_id', $board->id)->update(['sheet_id' => $sheetId]);

                    return;
                }

                $groups = [];

                foreach ($items as $item) {
                    $slots = DB::table('price_board_time_slots')
                        ->where('price_board_item_id', $item->id)
                        ->orderBy('timeslot_id')
                        ->get(['timeslot_id', 'price', 'over_night', 'status'])
                        ->map(fn ($s) => [(int) $s->timeslot_id, (int) $s->price, (bool) $s->over_night, $s->status])
                        ->all();

                    $signature = md5(json_encode([
                        (int) $item->room_style, $item->price, $item->default_checkin, $item->default_checkout,
                        $item->deposit_1_night, $item->deposit_multi_night, $item->deposit_min_nights,
                        $item->full_booking_discount, $item->bulk_discount_rules, $item->room_config, $slots,
                    ]));

                    $groups[$signature]['item'] ??= $item;
                    $groups[$signature]['slots'] ??= $slots;
                    $groups[$signature]['ids'][] = $item->id;
                    $groups[$signature]['products'][] = $item->product_id;
                }

                $order = 0;

                foreach ($groups as $group) {
                    /** @var object $item */
                    $item  = $group['item'];
                    $style = (int) $item->room_style ?: 1;
                    $fmt   = fn ($t) => $t ? substr((string) $t, 0, 5) : null;

                    $sheetId = DB::table('price_board_sheets')->insertGetId([
                        'price_board_id' => $board->id,
                        'name'           => $style === 2 ? 'Phòng theo ngày' : 'Phòng theo giờ' . (count($groups) > 1 ? ' ' . ($order + 1) : ''),
                        'pricing_mode'   => 'override',
                        'room_style'     => $style,
                        'is_default'     => false,
                        'sort_order'     => $order++,
                        'price'          => $item->price,
                        'default_checkin'  => $fmt($item->default_checkin),
                        'default_checkout' => $fmt($item->default_checkout),
                        'deposit_1_night'     => $item->deposit_1_night,
                        'deposit_multi_night' => $item->deposit_multi_night,
                        'deposit_min_nights'  => $item->deposit_min_nights,
                        'full_booking_discount' => $item->full_booking_discount,
                        'bulk_discount_rules'   => $item->bulk_discount_rules,
                        'room_config'           => $item->room_config,
                        // v1 luôn ghi cả 3 trường ưu đãi của item xuống phòng → coi như đã khai báo.
                        'fields_set' => json_encode(['full_booking_discount', 'bulk_discount_rules', 'room_config']),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);

                    foreach ($group['slots'] as $i => [$timeslotId, $price, $overNight, $status]) {
                        DB::table('price_board_sheet_slots')->insert([
                            'sheet_id' => $sheetId, 'timeslot_id' => $timeslotId, 'price' => $price,
                            'over_night' => $overNight, 'status' => $status ?: 'available', 'sort_order' => $i,
                        ]);
                    }

                    foreach (array_unique($group['products']) as $productId) {
                        DB::table('price_board_sheet_products')->insert(['sheet_id' => $sheetId, 'product_id' => $productId]);
                    }

                    DB::table('price_board_items')->whereIn('id', $group['ids'])->update(['sheet_id' => $sheetId]);
                }
            });
    }
};
