<?php

declare(strict_types=1);

namespace App\Services\PriceBoards;

use App\Services\PriceBoardSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * GIÁ THEO NGÀY KHÁCH Ở (bảng giá v2 — giai đoạn 2, mục 8.1 tài liệu).
 *
 * Giai đoạn 1 ghi giá bảng xuống phòng thành "giá hiện tại" rồi job đêm đồng bộ, nên đơn đặt trước cho
 * cuối tuần / ngày lễ bị tính giá của HÔM NAY. Ở giai đoạn 2 mọi nơi báo giá / tạo đơn hỏi service này:
 * "phòng X, khung Y, ngày ở Z giá bao nhiêu" — chọn bảng thắng của ĐÚNG ngày đó (priority, rồi phạm vi
 * hẹp hơn, xét khoảng ngày + days_of_week) và tính trên GIÁ GỐC của phòng, KHÔNG cộng dồn các bảng.
 *
 * Tắt cờ config('price_boards.phase2') → trả đúng giá hiện tại như trước (không đổi hành vi).
 *
 * Quy ước thứ (mục 3.4): khung ban ngày tính theo ngày của khung; khung qua đêm theo ngày BẮT ĐẦU
 * khung; phòng theo ngày tính từng đêm theo ngày nhận của đêm đó — nơi gọi truyền đúng ngày đó.
 */
class StayPriceResolver
{
    /** @var array<string, ?PriceBoardItem> */
    private array $winners = [];

    /** @var array<string, \Illuminate\Support\Collection<int, PriceBoardItem>> product_id => item của các bảng đang bật */
    private array $candidates = [];

    /** @var array<string, array<int,int>> product_id => [timeslot_id => giá gốc] */
    private array $baseSlots = [];

    /** @var array<string, ?float> */
    private array $baseNights = [];

    public function __construct(private PriceBoardSyncService $sync)
    {
    }

    public function enabled(): bool
    {
        return PriceBoard::phase2Enabled();
    }

    /** Giá khung giờ $rts của phòng vào ngày ở $date. */
    public function slotPrice(RoomTimeSlot $rts, Carbon|string $date): ?int
    {
        if (! $this->enabled() || $rts->price === null) {
            return $rts->price === null ? null : (int) $rts->price;
        }

        $productId = (string) $rts->room_id;
        $base      = $this->baseSlotPrice($productId, (int) $rts->timeslot_id, (int) $rts->price);
        $item      = $this->winner($productId, $date);

        if (! $item) {
            return $base;
        }

        $sheet = $item->sheet;

        if ($sheet && $sheet->isAdjustment() || (! $sheet && $item->priceBoard?->isAdjustment())) {
            $type  = $sheet?->adjustment_type ?? $item->priceBoard?->adjustment_type;
            $value = (float) ($sheet?->adjustment_value ?? $item->priceBoard?->adjustment_value ?? 0);
            $only  = collect((array) ($sheet?->adjustment_timeslot_ids ?? []))->map(fn ($v) => (int) $v)->all();

            if ($only && ! in_array((int) $rts->timeslot_id, $only, true)) {
                return $base;
            }

            return (int) $this->sync->applyAdjustment($base, $type, $value);
        }

        $slot  = $item->timeSlots->firstWhere('timeslot_id', $rts->timeslot_id);
        $price = $slot && $slot->price !== null ? (int) $slot->price : $base;

        // Tăng/giảm theo thứ + ngày lễ của bộ giá (phòng theo giờ): trên giá của khung này.
        return (int) $item->adjustSlotPrice((float) $price, $date);
    }

    /** Giá một đêm của phòng theo ngày vào ngày nhận $date; $legacy = giá hiện tại của phòng. */
    public function nightPrice(Product $room, Carbon|string $date, float $legacy): float
    {
        if (! $this->enabled()) {
            return $legacy;
        }

        $productId = (string) $room->id;
        $base      = $this->baseNight($productId, $legacy);
        $item      = $this->winner($productId, $date);

        if (! $item) {
            return $base;
        }

        $sheet = $item->sheet;

        if ($sheet && $sheet->isAdjustment() || (! $sheet && $item->priceBoard?->isAdjustment())) {
            return (float) $this->sync->applyAdjustment(
                $base,
                $sheet?->adjustment_type ?? $item->priceBoard?->adjustment_type,
                (float) ($sheet?->adjustment_value ?? $item->priceBoard?->adjustment_value ?? 0)
            );
        }

        return $item->priceForDate($date) ?? $base;
    }

    /**
     * Bảng giá kiểu "điều chỉnh" đang GIẢM giá khung $rts vào ngày $date — chỉ để web hiển thị như khuyến mãi (gạch giá gốc,
     * nhãn "Giảm 8%"); tiền khách trả vẫn là slotPrice(). null = không có bảng giảm giá nào đang áp.
     *
     * @return array{base: int, price: int, label: string, name: string}|null
     */
    public function boardDiscountForSlot(RoomTimeSlot $rts, Carbon|string $date): ?array
    {
        if (! $this->enabled() || $rts->price === null) {
            return null;
        }

        $item = $this->winner((string) $rts->room_id, $date);
        $adj  = $item ? $this->discountAdjustment($item) : null;

        if (! $adj || ($adj['only'] && ! in_array((int) $rts->timeslot_id, $adj['only'], true))) {
            return null;
        }

        $base = $this->baseSlotPrice((string) $rts->room_id, (int) $rts->timeslot_id, (int) $rts->price);

        return $this->discountResult($base, $adj, $item);
    }

    /** Như boardDiscountForSlot() cho 1 đêm của phòng theo ngày. */
    public function boardDiscountForNight(Product $room, Carbon|string $date, float $legacy): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $item = $this->winner((string) $room->id, $date);
        $adj  = $item ? $this->discountAdjustment($item) : null;

        return $adj ? $this->discountResult((int) round($this->baseNight((string) $room->id, $legacy)), $adj, $item) : null;
    }

    /** Điều chỉnh GIẢM (giá trị âm) của item thắng — từ bộ giá, không có bộ giá thì từ bảng. */
    private function discountAdjustment(PriceBoardItem $item): ?array
    {
        $sheet = $item->sheet;

        if (! ($sheet && $sheet->isAdjustment() || (! $sheet && $item->priceBoard?->isAdjustment()))) {
            return null;
        }

        $value = (float) ($sheet?->adjustment_value ?? $item->priceBoard?->adjustment_value ?? 0);

        return $value < 0 ? [
            'type'  => $sheet?->adjustment_type ?? $item->priceBoard?->adjustment_type,
            'value' => $value,
            'only'  => collect((array) ($sheet?->adjustment_timeslot_ids ?? []))->map(fn ($v) => (int) $v)->all(),
        ] : null;
    }

    private function discountResult(int $base, array $adj, PriceBoardItem $item): ?array
    {
        $price = (int) $this->sync->applyAdjustment($base, $adj['type'], $adj['value']);

        if ($price >= $base) {
            return null;
        }

        $amount = abs($adj['value']);
        $label  = $adj['type'] === 'fixed'
            ? 'Giảm ' . number_format($amount, 0, ',', '.') . 'đ'
            : 'Giảm ' . rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';

        return ['base' => $base, 'price' => $price, 'label' => $label, 'name' => (string) ($item->priceBoard?->name ?? $label)];
    }

    /** Bảng thắng của phòng vào ngày $date (null = giá gốc). */
    public function winningBoard(string $productId, Carbon|string $date): ?PriceBoard
    {
        return $this->winner($productId, $date)?->priceBoard;
    }

    private function winner(string $productId, Carbon|string $date): ?PriceBoardItem
    {
        $date = Carbon::parse($date)->startOfDay();
        $key  = $productId . '|' . $date->toDateString();

        if (! array_key_exists($key, $this->winners)) {
            // Nạp bảng của phòng 1 lần rồi chọn theo từng ngày — lịch 365 ngày không còn 365 truy vấn.
            $this->candidates[$productId] ??= $this->sync->candidateItems($productId);
            $this->winners[$key] = $this->sync->pickWinningItem($this->candidates[$productId], $date);
        }

        return $this->winners[$key];
    }

    /** GIÁ GỐC của 1 khung: ảnh chụp ở "Bảng giá mặc định" → giá gốc đóng băng của bảng → giá hiện tại. */
    private function baseSlotPrice(string $productId, int $timeslotId, int $current): int
    {
        if (! isset($this->baseSlots[$productId])) {
            $map = [];

            $default = PriceBoardItem::where('price_board_id', $this->sync->defaultBoard()->id)
                ->where('product_id', $productId)
                ->with('timeSlots')
                ->first();

            if ($default) {
                foreach ($default->timeSlots as $slot) {
                    if ($slot->price !== null) {
                        $map[(int) $slot->timeslot_id] = (int) $slot->price;
                    }
                }
            }

            if (! $map) {
                $frozen = PriceBoardItem::where('product_id', $productId)
                    ->whereHas('priceBoard', fn ($q) => $q->where('is_default', false)->where('is_template', false))
                    ->whereNotNull('baseline_time_slots')
                    ->latest('created_at')
                    ->first();

                foreach ((array) ($frozen?->baseline_time_slots ?? []) as $slot) {
                    if (isset($slot['timeslot_id'], $slot['price']) && $slot['price'] !== null) {
                        $map[(int) $slot['timeslot_id']] = (int) $slot['price'];
                    }
                }
            }

            $this->baseSlots[$productId] = $map;
        }

        return $this->baseSlots[$productId][$timeslotId] ?? $current;
    }

    private function baseNight(string $productId, float $legacy): float
    {
        if (! array_key_exists($productId, $this->baseNights)) {
            $default = PriceBoardItem::where('price_board_id', $this->sync->defaultBoard()->id)
                ->where('product_id', $productId)
                ->first();

            $price = $default?->price;

            if ($price === null) {
                $frozen = PriceBoardItem::where('product_id', $productId)
                    ->whereHas('priceBoard', fn ($q) => $q->where('is_default', false)->where('is_template', false))
                    ->whereNotNull('baseline_fields')
                    ->latest('created_at')
                    ->first();

                $price = $frozen?->baseline_fields['price'] ?? null;
            }

            $this->baseNights[$productId] = $price === null ? null : (float) $price;
        }

        return $this->baseNights[$productId] ?? $legacy;
    }
}
