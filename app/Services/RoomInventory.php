<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

/**
 * Tồn phòng của 1 phòng trong 1 khoảng thời gian.
 *
 * Phòng đơn (is_variant = false) có sức chứa 1 → kết quả giống hệt kiểm tra "có đơn nào chồng lịch" cũ.
 * Biến thể phòng (is_variant = true) có sức chứa variant_quantity: mỗi order_item giữ `quantity` phòng,
 * nên chỉ hết chỗ khi tổng số phòng của các đơn CHỒNG NHAU cùng lúc chạm sức chứa. Tính theo đỉnh
 * (sweep line) chứ không cộng dồn mọi đơn trong khoảng — 2 đơn nối tiếp nhau không chồng lên nhau
 * thì không chiếm 2 phòng.
 */
class RoomInventory
{
    public const ACTIVE_STATUSES = ['pending', 'paid', 'deposit', 'confirmed'];

    /** Số phòng đã bị giữ cao nhất tại một thời điểm bất kỳ trong [$from, $to). */
    public function peakBooked(
        Product $room,
        Carbon|string $from,
        Carbon|string $to,
        array $statuses = self::ACTIVE_STATUSES,
        ?int $excludeOrderId = null,
    ): int {
        $items = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $to)
            ->where('checkout_date', '>', $from)
            ->when($excludeOrderId, fn ($q) => $q->where('order_id', '!=', $excludeOrderId))
            // Đơn chờ thanh toán đã quá hạn không còn giữ phòng, kể cả khi cron orders:expire-pending chưa kịp chuyển
            // sang 'failed' — khớp với lịch (DailyRoomCalendar) để lịch và số phòng còn trống không lệch nhau.
            ->whereHas('order', fn ($q) => $q->whereIn('status', $statuses)->where(fn ($q) => $q
                ->where('status', '!=', 'pending')
                ->orWhereNull('expired_at')
                ->orWhere('expired_at', '>', now())))
            ->get(['checkin_date', 'checkout_date', 'quantity']);

        return $this->peak($items->concat($this->blockItems($room)), Carbon::parse($from), Carbon::parse($to));
    }

    /**
     * Khoảng admin khoá MỘT PHẦN số phòng của biến thể phòng (Product::partialBlockedRanges), quy về "đơn ảo" giữ
     * `rooms` phòng cho các đêm start..end (cả 2 đầu), cùng shape với order_item để cộng chung vào đỉnh tồn phòng.
     * Mốc là giờ trả phòng: đêm D chiếm [D giờ nhận, D+1 giờ trả) nằm trọn trong [start giờ trả, end+1 giờ trả).
     */
    public function blockItems(Product $room): Collection
    {
        $boundary = $room->default_checkout ?: '12:00';

        return collect($room->partialBlockedRanges())->map(fn ($range) => (object) [
            'checkin_date'  => Carbon::parse("{$range['start']} {$boundary}"),
            'checkout_date' => Carbon::parse($range['end'])->addDay()->format('Y-m-d') . " {$boundary}",
            'quantity'      => (int) $range['rooms'],
        ]);
    }

    /**
     * Đỉnh số phòng bị giữ từ các order_item đã nạp sẵn (vd quan hệ orderItems eager-load ở lịch phòng),
     * không truy vấn lại DB. $items chỉ cần checkin_date, checkout_date, quantity.
     */
    public function peak(Collection $items, Carbon $from, Carbon $to): int
    {
        $events = [];

        foreach ($items as $item) {
            $start = Carbon::parse($item->checkin_date)->max($from);
            $end   = Carbon::parse($item->checkout_date)->min($to);

            if ($start->gte($end)) {
                continue;
            }

            $qty      = max(1, (int) ($item->quantity ?? 1));
            $events[] = [$start->getTimestamp(), $qty];
            $events[] = [$end->getTimestamp(), -$qty];
        }

        // Cùng mốc thời gian: trả phòng (-) trước nhận phòng (+), để đơn này trả đúng lúc đơn sau nhận không bị tính chồng.
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $current = $max = 0;
        foreach ($events as [, $delta]) {
            $current += $delta;
            $max      = max($max, $current);
        }

        return $max;
    }

    /**
     * Các khoảng thời gian đã HẾT phòng (số phòng giữ cùng lúc >= $capacity), dạng [[start, end], ...] — dùng
     * để lịch chỉ tô xám đúng lúc biến thể phòng hết sạch, không phải mọi lúc có đơn.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    public function fullIntervals(Collection $items, int $capacity): array
    {
        $events = [];
        foreach ($items as $item) {
            if (! $item->checkin_date || ! $item->checkout_date) {
                continue;
            }
            $qty      = max(1, (int) ($item->quantity ?? 1));
            $events[] = [Carbon::parse($item->checkin_date)->getTimestamp(), $qty];
            $events[] = [Carbon::parse($item->checkout_date)->getTimestamp(), -$qty];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $intervals = [];
        $current   = 0;
        $fullSince = null;
        foreach ($events as [$at, $delta]) {
            $current += $delta;

            if ($current >= $capacity && $fullSince === null) {
                $fullSince = $at;
            } elseif ($current < $capacity && $fullSince !== null) {
                if ($at > $fullSince) {
                    $intervals[] = [Carbon::createFromTimestamp($fullSince), Carbon::createFromTimestamp($at)];
                }
                $fullSince = null;
            }
        }

        return $intervals;
    }

    public function remaining(Product $room, Carbon|string $from, Carbon|string $to, array $statuses = self::ACTIVE_STATUSES, ?int $excludeOrderId = null): int
    {
        [$from, $to] = $this->stayWindow($room, $from, $to);

        return max(0, $room->roomCapacity() - $this->peakBooked($room, $from, $to, $statuses, $excludeOrderId));
    }

    /**
     * Khoảng theo NGÀY (00:00 → 00:00, cách luồng đặt theo ngày truyền vào) của biến thể phòng được đổi sang giờ nhận/trả
     * phòng thật (vd 14:00 → 12:00 hôm sau). Không đổi thì sáng ngày nhận phòng — lúc khách đêm trước chưa trả phòng —
     * bị tính chồng, làm đêm còn phòng hiện thành hết. Phòng đơn giữ nguyên cách tính cũ.
     */
    private function stayWindow(Product $room, Carbon|string $from, Carbon|string $to): array
    {
        $from = Carbon::parse($from);
        $to   = Carbon::parse($to);

        if (! $room->is_variant || $from->format('H:i:s') !== '00:00:00' || $to->format('H:i:s') !== '00:00:00') {
            return [$from, $to];
        }

        return [
            Carbon::parse($from->format('Y-m-d') . ' ' . ($room->default_checkin ?: '14:00')),
            Carbon::parse($to->format('Y-m-d') . ' ' . ($room->default_checkout ?: '12:00')),
        ];
    }

    public function hasRoom(Product $room, Carbon|string $from, Carbon|string $to, int $quantity = 1, array $statuses = self::ACTIVE_STATUSES, ?int $excludeOrderId = null): bool
    {
        return $this->remaining($room, $from, $to, $statuses, $excludeOrderId) >= max(1, $quantity);
    }

    /** Ném lỗi 422 ở $field khi không đủ $quantity phòng; biến thể phòng thì báo rõ còn bao nhiêu phòng. */
    public function assertAvailable(
        Product $room,
        Carbon|string $from,
        Carbon|string $to,
        int $quantity,
        string $field,
        string $message,
        array $statuses = self::ACTIVE_STATUSES,
        ?int $excludeOrderId = null,
    ): void {
        $remaining = $this->remaining($room, $from, $to, $statuses, $excludeOrderId);

        if ($remaining >= max(1, $quantity)) {
            return;
        }

        if ($room->is_variant && $remaining > 0) {
            $message = "Chỉ còn {$remaining} phòng trống trong khoảng thời gian này.";
        }

        throw ValidationException::withMessages([$field => [$message]]);
    }

    /**
     * Dòng báo tồn phòng cho thông báo đơn: với mỗi biến thể phòng trong đơn, còn trống bao nhiêu phòng trong
     * khoảng ngày của đơn (đã trừ cả đơn này nếu đơn đang giữ phòng). Phòng đơn không có dòng nào.
     *
     * @return array<int, string> vd "Phòng DBL Balcony: còn trống 2/5 phòng (05/11 → 06/11)"
     */
    public function orderStockLines(\Modules\Payment\Entities\Order $order): array
    {
        $order->loadMissing('items.product');

        return $order->items
            ->filter(fn ($item) => (float) $item->extra_fee == 0 && $item->product?->is_variant && $item->checkin_date && $item->checkout_date)
            ->groupBy('product_id')
            ->map(function (Collection $items) {
                $room     = $items->first()->product;
                $capacity = $room->roomCapacity();

                // Mỗi order_item là 1 đêm / 1 khung giờ → tồn phòng tính riêng từng dòng; các dòng bằng nhau thì gộp 1 số.
                $perItem = $items->sortBy('checkin_date')->map(fn ($item) => [
                    'label' => Carbon::parse($item->checkin_date)->format((int) $room->styles === 1 ? 'H:i d/m' : 'd/m'),
                    'left'  => $this->remaining($room, $item->checkin_date, $item->checkout_date),
                ])->values();

                if ($perItem->pluck('left')->unique()->count() === 1) {
                    $from = Carbon::parse($items->min('checkin_date'))->format('d/m');
                    $to   = Carbon::parse($items->max('checkout_date'))->format('d/m');

                    return "{$room->name}: còn trống {$perItem[0]['left']}/{$capacity} phòng ({$from} → {$to})";
                }

                return "{$room->name}: còn trống " . $perItem->map(fn ($p) => "{$p['label']}: {$p['left']}/{$capacity}")->implode(' · ') . ' phòng';
            })
            ->values()
            ->all();
    }

    /**
     * Thêm $newItems (cùng shape order_item: checkin_date, checkout_date, quantity) vào biến thể phòng có vượt sức chứa
     * không. Trả về khoảng đầu tiên bị vượt [từ, đến] hoặc null nếu vẫn đủ phòng. Dùng khi 1 đơn có nhiều dòng cùng
     * phòng (admin tạo đơn) — kiểm tra từng dòng riêng lẻ sẽ bỏ sót trường hợp các dòng của chính đơn cộng lại vượt.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function firstOverbooking(Product $room, Collection $newItems, array $excludeItemIds = [], ?int $excludeOrderId = null): ?array
    {
        if ($newItems->isEmpty()) {
            return null;
        }

        $from = Carbon::parse($newItems->min('checkin_date'));
        $to   = Carbon::parse($newItems->max('checkout_date'));

        $existing = OrderItem::where('product_id', $room->id)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $to)
            ->where('checkout_date', '>', $from)
            ->when($excludeItemIds, fn ($q) => $q->whereNotIn('id', $excludeItemIds))
            ->when($excludeOrderId, fn ($q) => $q->where('order_id', '!=', $excludeOrderId))
            ->whereHas('order', fn ($q) => $q->whereIn('status', self::ACTIVE_STATUSES)->where(fn ($q) => $q
                ->where('status', '!=', 'pending')
                ->orWhereNull('expired_at')
                ->orWhere('expired_at', '>', now())))
            ->get(['checkin_date', 'checkout_date', 'quantity']);

        $full = $this->fullIntervals(
            $existing->concat($this->blockItems($room))->concat($newItems),
            $room->roomCapacity() + 1,
        );

        return $full[0] ?? null;
    }

    /** Số phòng khách yêu cầu, kẹp trong [1, sức chứa]; phòng đơn luôn là 1. */
    public function requestedQuantity(Product $room, mixed $input): int
    {
        if (! $room->is_variant) {
            return 1;
        }

        return max(1, min((int) ($input ?: 1), $room->roomCapacity()));
    }
}
