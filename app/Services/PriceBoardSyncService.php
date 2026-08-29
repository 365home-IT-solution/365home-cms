<?php

namespace App\Services;

use Carbon\Carbon;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\PriceBoardPriceLog;
use Modules\Product\App\Models\PriceBoardTimeSlot;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Đồng bộ giữa "bảng giá" (price_boards/price_board_items) và dữ liệu giá thật mà toàn bộ pipeline
 * đặt phòng đang đọc (products/room_time_slots) — xem plan "Chuyển module Hệ thống giá thành module
 * Bảng giá": products/room_time_slots vẫn là nguồn duy nhất cho tính giá, bảng giá chỉ là lớp quản
 * trị ở trên, ghi đè xuống theo lịch rồi khôi phục lại khi hết hạn.
 *
 * Chỉ áp dụng cho phòng kiểu "Theo Khung Giờ" (styles=1, room_time_slots type=time) và các field giá
 * chung trên Product. KHÔNG đụng tới room_time_slots type=date (lịch "Ngày đặc biệt" của style=2) —
 * đó là tính năng lịch chi tiết riêng đã có sẵn ở SettingBook, không thuộc phạm vi bảng giá đặt tên.
 */
class PriceBoardSyncService
{
    /** Ghi $data (mảng 'items' hoặc 'product_ids' lấy từ form PriceBoardResource) xuống
     *  PriceBoardItem/PriceBoardTimeSlot — dùng chung cho Create/Edit VÀ cho các nút "Áp dụng hàng
     *  loạt" trong form (ghi thẳng xuống DB ngay khi đang sửa 1 bảng đã tồn tại, không phụ thuộc vào
     *  việc bấm "Lưu thay đổi" hay TableRepeater có tự vẽ lại đúng giá trị mới hay không — xem
     *  PriceBoardForm::applyBulkByPosition()/applyBulkByTimeslot() để biết lý do). */
    public function saveItems(PriceBoard $board, array $data): void
    {
        if ($board->isAdjustment()) {
            $this->saveProductIds($board, $data['product_ids'] ?? []);

            return;
        }

        $this->saveOverrideItems($board, $data['items'] ?? []);
    }

    public function saveProductIds(PriceBoard $board, array $productIds): void
    {
        $keepItemIds = [];

        foreach (array_filter($productIds) as $productId) {
            $item = PriceBoardItem::updateOrCreate(
                ['price_board_id' => $board->id, 'product_id' => $productId],
                []
            );

            $item->timeSlots()->delete();
            $keepItemIds[] = $item->id;
        }

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) {
            $item->timeSlots()->delete();
            $item->delete();
        });
    }

    public function saveOverrideItems(PriceBoard $board, array $items): void
    {
        $keepItemIds = [];

        foreach ($items as $itemData) {
            if (empty($itemData['product_id'])) {
                continue;
            }

            $product = Product::find($itemData['product_id']);
            if (! $product) {
                continue;
            }

            $style = (int) ($product->styles ?? 1);

            $rawRules  = $itemData['bulk_discount_rules'] ?? [];
            $bulkRules = collect($rawRules)
                ->filter(fn ($r) => isset($r['slots'], $r['discount']))
                ->map(fn ($r) => ['slots' => (int) $r['slots'], 'discount' => (float) $r['discount']])
                ->sortBy('slots')
                ->values()
                ->toArray();

            $fields = [
                'full_booking_discount' => $itemData['full_booking_discount'] ?? null,
                'bulk_discount_rules'   => $bulkRules ?: null,
                'room_config'           => [
                    'max_free_guests' => (int) ($itemData['room_config_max_free_guests'] ?? 2),
                    'extra_guest_fee' => (int) preg_replace('/[^0-9]/', '', (string) ($itemData['room_config_extra_guest_fee'] ?? '0')),
                ],
            ];

            if ($style === 2) {
                $fields['price']               = $itemData['price'] ?? null;
                $fields['default_checkin']     = $itemData['default_checkin'] ?? '14:00';
                $fields['default_checkout']    = $itemData['default_checkout'] ?? '12:00';
                $fields['deposit_min_nights']  = (int) ($itemData['deposit_min_nights'] ?? 2);
                $fields['deposit_multi_night'] = (int) ($itemData['deposit_multi_night'] ?? 50);
                $fields['deposit_1_night']     = 100;
            }

            $item = PriceBoardItem::updateOrCreate(
                ['price_board_id' => $board->id, 'product_id' => $product->id],
                $fields
            );

            $keepItemIds[] = $item->id;

            $item->timeSlots()->delete();

            if ($style === 1) {
                foreach ($itemData['roomTimeSlots'] ?? [] as $slot) {
                    if (empty($slot['timeslot_id']) || $slot['price'] === null || $slot['price'] === '') {
                        continue;
                    }

                    PriceBoardTimeSlot::create([
                        'price_board_item_id' => $item->id,
                        'timeslot_id'          => $slot['timeslot_id'],
                        'price'                => (int) str_replace(['.', ','], '', (string) $slot['price']),
                        'over_night'           => $slot['over_night'] ?? false,
                        'status'               => $slot['status'] ?? 'available',
                    ]);
                }
            }
        }

        $board->items()->whereNotIn('id', $keepItemIds ?: [0])->get()->each(function (PriceBoardItem $item) {
            $item->timeSlots()->delete();
            $item->delete();
        });
    }

    public function defaultBoard(): PriceBoard
    {
        return PriceBoard::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Bảng giá mặc định', 'is_active' => true]
        );
    }

    /** Ghi lại trạng thái giá hiện tại của $product vào bảng mặc định — gọi mỗi khi admin lưu giá
     *  qua trang "Hệ thống giá" (SettingBook) để bảng mặc định luôn là ảnh chụp mới nhất, dùng làm
     *  điểm khôi phục khi 1 bảng giá đặt tên hết hạn. */
    public function seedDefaultBoard(Product $product): PriceBoardItem
    {
        $board = $this->defaultBoard();

        $item = PriceBoardItem::updateOrCreate(
            ['price_board_id' => $board->id, 'product_id' => $product->id],
            $this->extractProductFields($product)
        );

        $item->timeSlots()->delete();

        // groupBy+last: nếu room_time_slots lỡ có 2 dòng trùng timeslot_id (dữ liệu bẩn), chỉ mirror
        // đúng 1 dòng (mới nhất theo id) — tránh nhân bản trùng lặp lan sang bảng mặc định rồi tái
        // tạo lại y hệt mỗi lần khôi phục.
        $recurringSlots = $product->roomTimeSlots()
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->get()
            ->groupBy('timeslot_id')
            ->map(fn ($group) => $group->sortBy('id')->last());

        foreach ($recurringSlots as $slot) {
            PriceBoardTimeSlot::create([
                'price_board_item_id' => $item->id,
                'timeslot_id'         => $slot->timeslot_id,
                'price'               => $slot->price,
                'checkin'             => $slot->checkin,
                'checkout'            => $slot->checkout,
                'over_night'          => $slot->over_night,
                'status'              => $slot->status,
            ]);
        }

        return $item->fresh('timeSlots');
    }

    /** Áp giá đang hiệu lực cho $product tại $date (mặc định hôm nay): tìm bảng đặt tên (không phải
     *  mặc định) đang active và có khoảng ngày phủ $date; nếu không có, khôi phục về bảng mặc định. */
    public function applyForProduct(Product $product, ?Carbon $date = null): void
    {
        $date = ($date ?? now())->copy()->startOfDay();

        $item = PriceBoardItem::where('product_id', $product->id)
            ->whereHas('priceBoard', function ($q) use ($date) {
                $q->where('is_active', true)
                    ->where('is_default', false)
                    ->where(fn ($q2) => $q2->whereNull('start_date')->orWhereDate('start_date', '<=', $date))
                    ->where(fn ($q2) => $q2->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
            })
            ->with('timeSlots')
            ->first();

        if (! $item) {
            $item = PriceBoardItem::where('price_board_id', $this->defaultBoard()->id)
                ->where('product_id', $product->id)
                ->with('timeSlots')
                ->first();
        }

        if (! $item) {
            return; // Chưa từng seed bảng mặc định cho phòng này — không có gì để áp.
        }

        $this->writeItemToProduct($item, $product);
    }

    /** Áp NGAY item của $board xuống các phòng gắn với nó, BẤT KỂ ngày hiệu lực/is_active — dùng
     *  cho nút "Áp dụng ngay" khi đối tác cần đổi giá tức thời dù chưa tới ngày. */
    public function applyBoard(PriceBoard $board): void
    {
        foreach ($board->items()->with(['timeSlots', 'product'])->get() as $item) {
            if ($item->product) {
                $this->writeItemToProduct($item, $item->product);
            }
        }
    }

    /** Tính lại NGAY giá đang đúng phải áp cho từng phòng gắn với $board (áp nếu $board đang thắng,
     *  khôi phục bảng khác/mặc định nếu không) — gọi ngay sau khi tạo/sửa/bật/tắt 1 bảng để có hiệu
     *  lực tức thời, không phải chờ job `price-boards:sync-due` chạy lúc nửa đêm. */
    public function resyncBoardProducts(PriceBoard $board): void
    {
        foreach ($board->items()->with('product')->get() as $item) {
            if ($item->product) {
                $this->applyForProduct($item->product);
            }
        }
    }

    /** Chặn lưu nếu khoảng ngày hiệu lực của $board trùng với 1 bảng active khác (không phải mặc
     *  định) đã áp cho cùng ít nhất 1 phòng — tránh phải xử lý ưu tiên khi 2 bảng cùng khớp 1 ngày. */
    public function assertNoOverlap(PriceBoard $board, ?\Illuminate\Support\Collection $productIds = null): void
    {
        if ($board->is_default) {
            return;
        }

        $productIds = $productIds ?? $board->items()->pluck('product_id');
        if ($productIds->isEmpty()) {
            return;
        }

        $othersQuery = PriceBoard::where('is_default', false)->where('is_active', true);

        if ($board->exists) {
            $othersQuery->where('id', '!=', $board->id);
        }

        $others = $othersQuery
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->get();

        foreach ($others as $other) {
            if ($this->rangesOverlap($board, $other)) {
                throw new \RuntimeException(
                    "Khoảng ngày hiệu lực trùng với bảng giá \"{$other->name}\" cho ít nhất 1 phòng đã chọn."
                );
            }
        }
    }

    /** "Khung giờ tái sử dụng" (type='time') KHÁC "ngày đặc biệt" (type='date') — dữ liệu cũ trước
     *  khi có cột `type` để lại nhiều TimeSlot với type=NULL, vẫn phải coi là khung giờ tái sử dụng
     *  (không phải ngày đặc biệt) chứ không được loại khỏi phạm vi đồng bộ của bảng giá. */
    private function scopeRecurring($query)
    {
        return $query->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'));
    }

    private function rangesOverlap(PriceBoard $a, PriceBoard $b): bool
    {
        $aStart = $a->start_date ? Carbon::parse($a->start_date) : Carbon::minValue();
        $aEnd   = $a->end_date ? Carbon::parse($a->end_date) : Carbon::maxValue();
        $bStart = $b->start_date ? Carbon::parse($b->start_date) : Carbon::minValue();
        $bEnd   = $b->end_date ? Carbon::parse($b->end_date) : Carbon::maxValue();

        return $aStart->lte($bEnd) && $bStart->lte($aEnd);
    }

    private function writeItemToProduct(PriceBoardItem $item, Product $product): void
    {
        $before = $this->snapshotPricing($product);

        ['fields' => $fields, 'timeSlots' => $timeSlots] = $this->resolveEffectivePayload($item, $product);

        $product->update($fields);

        $recurringSlotIds = RoomTimeSlot::where('room_id', $product->id)
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->pluck('id');

        RoomTimeSlot::whereIn('id', $recurringSlotIds)->get()->each(function (RoomTimeSlot $slot) {
            $slot->promotions()->detach();
            $slot->coupons()->detach();
            $slot->delete();
        });

        foreach ($timeSlots as $boardSlot) {
            RoomTimeSlot::create([
                'room_id'                    => $product->id,
                'timeslot_id'                => $boardSlot->timeslot_id,
                'price'                      => $boardSlot->price,
                'checkin'                    => $boardSlot->checkin,
                'checkout'                   => $boardSlot->checkout,
                'over_night'                 => $boardSlot->over_night,
                'status'                     => $boardSlot->status,
                'synced_from_price_board_id' => $item->price_board_id,
            ]);
        }

        $after = $this->snapshotPricing($product->fresh());

        $this->logPriceChange($item->price_board_id, $product, $before, $after);
    }

    /** Chụp giá hiện tại của $product (giá/đêm + giá từng khung giờ tái sử dụng, theo tên khung
     *  giờ) — so sánh trước/sau mỗi lần đổi giá để biết CÓ THẬT SỰ đổi gì không, dùng cho popup
     *  "Lịch sử thay đổi giá" (không ghi log nếu giá không đổi, tránh nhiễu). Public để
     *  SettingBook::save() và "Sửa giá hàng loạt" (HasBookingHeaderActions) cũng gọi được — không
     *  chỉ riêng lúc áp bảng giá đặt tên. */
    public function snapshotPricing(Product $product): array
    {
        $slots = RoomTimeSlot::where('room_id', $product->id)
            ->whereHas('timeSlot', fn ($q) => $this->scopeRecurring($q))
            ->with('timeSlot')
            ->get()
            ->mapWithKeys(fn (RoomTimeSlot $slot) => [
                ($slot->timeSlot->label ?? (string) $slot->timeslot_id) => $slot->price === null ? null : (int) $slot->price,
            ])
            ->toArray();

        return [
            'price' => $product->price === null ? null : (float) $product->price,
            'slots' => $slots,
        ];
    }

    /** Ghi 1 dòng lịch sử NẾU giá thật sự đổi — dùng bảng riêng price_board_price_logs (không dùng
     *  AuditLog chung của hệ thống) vì AuditLogger::log() chỉ ghi khi có auth()->user(), trong khi
     *  phần lớn thay đổi giá ở đây chạy tự động qua lịch (price-boards:sync-due), không có ai đăng
     *  nhập — changed_by=null nghĩa là "hệ thống tự áp theo lịch". Public để log được cả những lần
     *  đổi giá KHÔNG qua bảng giá đặt tên (sửa tay ở "Hệ thống giá", "Sửa giá hàng loạt") — những lúc
     *  đó truyền $priceBoardId = defaultBoard()->id, gắn vào "Bảng giá mặc định" cho thống nhất. */
    public function logPriceChange(int $priceBoardId, Product $product, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }

        PriceBoardPriceLog::create([
            'price_board_id' => $priceBoardId,
            'product_id'     => $product->id,
            'old_price'      => $before['price'],
            'new_price'      => $after['price'],
            'old_slots'      => $before['slots'] ?: null,
            'new_slots'      => $after['slots'] ?: null,
            'changed_by'     => auth()->id(),
            'created_at'     => now(),
        ]);
    }

    /** 'override': dùng thẳng giá đã nhập trong $item. 'adjustment': lấy giá GỐC từ item của bảng
     *  mặc định (luôn là ảnh chụp mới nhất từ SettingBook) rồi cộng/trừ % hoặc số tiền của $board —
     *  không cần nhập lại giá từng phòng, tự tính lại mỗi lần áp dựa trên giá gốc hiện tại. */
    private function resolveEffectivePayload(PriceBoardItem $item, Product $product): array
    {
        $board = $item->priceBoard ?? $item->priceBoard()->first();

        if (! $board || ! $board->isAdjustment()) {
            return [
                'fields'    => $this->itemToProductFields($item),
                'timeSlots' => $item->timeSlots,
            ];
        }

        $baseline = PriceBoardItem::where('price_board_id', $this->defaultBoard()->id)
            ->where('product_id', $product->id)
            ->with('timeSlots')
            ->first();

        if (! $baseline) {
            return [
                'fields'    => $this->extractProductFields($product),
                'timeSlots' => collect(),
            ];
        }

        $fields          = $this->itemToProductFields($baseline);
        $fields['price'] = $this->applyAdjustment($fields['price'], $board);

        $timeSlots = $baseline->timeSlots->map(function (PriceBoardTimeSlot $slot) use ($board) {
            $adjusted        = clone $slot;
            $adjusted->price = $this->applyAdjustment($slot->price, $board);

            return $adjusted;
        });

        return ['fields' => $fields, 'timeSlots' => $timeSlots];
    }

    private function applyAdjustment(mixed $basePrice, PriceBoard $board): ?int
    {
        if ($basePrice === null) {
            return null;
        }

        $value = (float) $board->adjustment_value;

        if ($board->adjustment_type === 'fixed') {
            return (int) round((float) $basePrice + $value);
        }

        return (int) round((float) $basePrice * (1 + $value / 100));
    }

    private function extractProductFields(Product $product): array
    {
        return [
            'price'                 => $product->price,
            'price_unit'            => $product->price_unit,
            'full_booking_discount' => $product->full_booking_discount,
            'bulk_discount_rules'   => $product->bulk_discount_rules,
            'room_config'           => $product->room_config,
            'deposit_1_night'       => $product->deposit_1_night,
            'deposit_multi_night'   => $product->deposit_multi_night,
            'deposit_min_nights'    => $product->deposit_min_nights,
            'default_checkin'       => $product->default_checkin,
            'default_checkout'      => $product->default_checkout,
        ];
    }

    private function itemToProductFields(PriceBoardItem $item): array
    {
        return [
            'price'                 => $item->price,
            'price_unit'            => $item->price_unit,
            'full_booking_discount' => $item->full_booking_discount,
            'bulk_discount_rules'   => $item->bulk_discount_rules,
            'room_config'           => $item->room_config,
            'deposit_1_night'       => $item->deposit_1_night,
            'deposit_multi_night'   => $item->deposit_multi_night,
            'deposit_min_nights'    => $item->deposit_min_nights,
            'default_checkin'       => $item->default_checkin,
            'default_checkout'      => $item->default_checkout,
        ];
    }
}
