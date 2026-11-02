<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use App\Services\SlotRealtimeService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\BladeThemeV1\Support\DailyRoomCalendar;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

// View "Lịch" › "Theo ngày" ở Dashboard — lịch tháng của phòng ĐẶT THEO NGÀY (styles=2), cùng giao diện lịch phía khách
// (BladeThemeV1 book/_daily-cards.blade.php) và cùng nguồn giá/ngày kín (DailyRoomCalendar::data()), thêm phần chỉ admin
// cần: đơn nào đang ở từng đêm, ngày nhận/trả, khoá/mở khoá khoảng ngày (room_config.blocked_ranges — đúng chỗ
// BlockTimeslotModal style=2 ghi).
//
// Không nằm trong payload poll room-cards: DailyRoomCalendar::data() tính giá ~400 ngày mỗi phòng (~50ms/phòng) — chỉ
// tải cho các phòng của chi nhánh đang xem, khi cần.
class DailyCalendarService
{
    private const STATUS_LABELS = [
        'pending'   => 'Chờ xác nhận',
        'deposit'   => 'Đặt cọc',
        'paid'      => 'Đã thanh toán',
        'shipping'  => 'Đang xử lý',
        'completed' => 'Hoàn thành',
        'confirmed' => 'Đã xác nhận',
    ];

    private const STATUS_COLORS = [
        'pending'   => '#f59e0b',
        'deposit'   => '#d97757',
        'paid'      => '#10b981',
        'shipping'  => '#3b82f6',
        'completed' => '#8b5cf6',
        'confirmed' => '#10b981',
    ];

    // Phòng mà tài khoản được thao tác — cùng luật lọc chi nhánh với RoomCardsService::getData() và các route
    // rooms/{id}/... ở routes/web.php (Product tự lọc partner_id qua BelongsToPartner).
    public static function roomQuery($user): Builder
    {
        $query = Product::query();

        if ($user && ! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', fn ($q2) => $q2->whereIn('categories.id', $allowedCategoryIds))
                        ->orWhereDoesntHave('categories');
                });
            }
        }

        return $query;
    }

    /** @param array<int, string> $productIds */
    public static function forRooms($user, array $productIds): array
    {
        $products = self::roomQuery($user)
            ->whereIn('id', array_slice(array_values(array_unique($productIds)), 0, 40))
            ->where('styles', 2)
            ->where('is_activated', true)
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return ['rooms' => [], 'today' => Carbon::today()->toDateString()];
        }

        $ids          = $products->pluck('id')->map(fn ($id) => (string) $id)->all();
        $bookedRanges = DailyRoomCalendar::bookedRangesFor($ids);
        $occupancy    = self::occupancy($ids);

        $rooms = $products->map(function (Product $product) use ($bookedRanges, $occupancy) {
            $id = (string) $product->id;

            return [
                'product_id'     => $id,
                'room_name'      => $product->name,
                'is_variant'     => (bool) $product->is_variant,
                'capacity'       => $product->roomCapacity(),
                'checkin_time'   => substr((string) ($product->default_checkin ?? '14:00'), 0, 5),
                'checkout_time'  => substr((string) ($product->default_checkout ?? '12:00'), 0, 5),
                'calendar'       => DailyRoomCalendar::data($product, $bookedRanges[$id] ?? []),
                // Mọi khoảng khoá (kể cả khoá một phần số phòng của biến thể) — để hiện "khoá 1/5" và mở khoá đúng khoảng.
                'blocked_ranges' => array_values(array_filter(
                    is_array($product->room_config) ? ($product->room_config['blocked_ranges'] ?? []) : [],
                    fn ($r) => ! empty($r['start']) && ! empty($r['end']),
                )),
                'nights'         => $occupancy['nights'][$id] ?? (object) [],
                'checkouts'      => $occupancy['checkouts'][$id] ?? (object) [],
            ];
        })->values()->all();

        return ['rooms' => $rooms, 'today' => Carbon::today()->toDateString()];
    }

    // Đơn đang giữ phòng theo từng đêm (từ đầu tháng trước tới 13 tháng sau) — 1 query cho mọi phòng. Đêm = các ngày
    // nhận..trả-1 (ở trong ngày: chính ngày nhận); 'checkouts' đếm khách trả phòng theo ngày để hiện mốc "trả".
    private static function occupancy(array $productIds): array
    {
        $from = Carbon::today()->startOfMonth()->subMonth();
        $to   = Carbon::today()->addMonths(13);

        $items = OrderItem::with('order')
            ->whereIn('product_id', $productIds)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->whereDate('checkout_date', '>=', $from)
            ->whereDate('checkin_date', '<=', $to)
            ->whereHas('order', function ($q) {
                $q->whereIn('status', array_keys(self::STATUS_LABELS))
                    ->where(function ($q2) {
                        $q2->where('status', '!=', 'pending')
                            ->orWhereNull('expired_at')
                            ->orWhere('expired_at', '>', now());
                    });
            })
            ->orderBy('checkin_date')
            ->get();

        $nights    = [];
        $checkouts = [];

        foreach ($items as $item) {
            $order = $item->order;
            if (! $order) {
                continue;
            }

            $pid     = (string) $item->product_id;
            $start   = $item->checkin_date->copy()->startOfDay();
            $end     = $item->checkout_date->copy()->startOfDay();
            $lastDay = $end->gt($start) ? $end->copy()->subDay() : $start->copy();
            $entry   = [
                'order_id'   => $order->id,
                'order_code' => $order->order_code ?? '—',
                'buyer_name' => $order->buyer_name ?? 'Khách',
                'status'     => $order->status,
                'status_label' => self::STATUS_LABELS[$order->status] ?? $order->status,
                'color'      => self::STATUS_COLORS[$order->status] ?? '#94a3b8',
                'has_note'   => filled($order->description),
                'quantity'   => max(1, (int) ($item->quantity ?? 1)),
                'checkin'    => $start->toDateString(),
                'checkout'   => $end->toDateString(),
            ];

            for ($day = $start->copy()->max($from); $day->lte($lastDay) && $day->lte($to); $day->addDay()) {
                $nights[$pid][$day->toDateString()][] = $entry;
            }

            if ($end->gt($start)) {
                $checkouts[$pid][$end->toDateString()] = ($checkouts[$pid][$end->toDateString()] ?? 0) + 1;
            }
        }

        return ['nights' => $nights, 'checkouts' => $checkouts];
    }

    // Khoá các ngày $start..$end (tính cả 2 đầu — đúng nghĩa blocked_ranges mà lịch khách/BlockTimeslotModal đang đọc).
    // Biến thể phòng: $rooms = số phòng khoá thêm (bỏ trống = cả loại); khoá chồng lên ngày đã khoá thì cộng dồn, đúng cách
    // RoomInventory::peakBooked() cộng các khoảng khoá một phần.
    public static function block(Product $product, string $start, string $end, ?int $rooms = null): void
    {
        if ((int) $product->styles !== 2) {
            throw ValidationException::withMessages(['start' => 'Phòng này không đặt theo ngày.']);
        }

        $capacity = $product->roomCapacity();
        $rooms    = $product->is_variant ? min($capacity, max(1, $rooms ?? $capacity)) : 1;

        // Số phòng còn trống thấp nhất trong khoảng (đã trừ đơn + phần đang khoá một phần), tính theo mốc giờ trả phòng như
        // RoomInventory::blockItems() — ngày trả phòng của khách trước vẫn khoá được.
        $boundary = $product->default_checkout ?: '12:00';
        $free     = app(\App\Services\RoomInventory::class)->remaining(
            $product,
            Carbon::parse("{$start} {$boundary}"),
            Carbon::parse($end)->addDay()->format('Y-m-d') . " {$boundary}",
        );

        if ($rooms > $free) {
            throw ValidationException::withMessages(['rooms' => $product->is_variant
                ? "Khoảng ngày này chỉ còn {$free}/{$capacity} phòng trống — không khoá được {$rooms} phòng."
                : 'Khoảng ngày này đã có khách đặt — không khoá được.']);
        }

        self::saveRanges($product, self::rebuildRanges($product, $start, $end, $rooms));
    }

    // Mở khoá đúng các ngày $start..$end (cả khoá trọn lẫn khoá một phần) — khoảng khoá dài bị cắt giữa thì tách đôi. Trả về
    // số ngày đã mở.
    public static function unblock(Product $product, string $start, string $end): int
    {
        $before = self::blockedCounts($product);
        $opened = count(array_filter(self::daysBetween($start, $end), fn ($day) => ! empty($before[$day])));

        if ($opened > 0) {
            self::saveRanges($product, self::rebuildRanges($product, $start, $end, 0));
        }

        return $opened;
    }

    private static function currentRanges(Product $product): array
    {
        return array_values(array_filter(
            is_array($product->room_config) ? ($product->room_config['blocked_ranges'] ?? []) : [],
            fn ($r) => ! empty($r['start']) && ! empty($r['end']),
        ));
    }

    /** @return array<string, int> ngày => số phòng đang khoá (khoá trọn = sức chứa; các khoảng chồng nhau cộng dồn) */
    private static function blockedCounts(Product $product): array
    {
        $capacity = $product->roomCapacity();
        $counts   = [];

        foreach (self::currentRanges($product) as $r) {
            $rooms = $product->is_variant && ! empty($r['rooms']) ? min($capacity, (int) $r['rooms']) : $capacity;
            foreach (self::daysBetween($r['start'], $r['end']) as $day) {
                $counts[$day] = min($capacity, ($counts[$day] ?? 0) + $rooms);
            }
        }

        return $counts;
    }

    // Số phòng khoá theo từng ngày: $rooms > 0 thì cộng thêm vào $start..$end, 0 thì mở hết các ngày đó; rồi gộp các ngày
    // liền nhau cùng số phòng thành 1 khoảng (khoá trọn không ghi 'rooms').
    private static function rebuildRanges(Product $product, string $start, string $end, int $rooms): array
    {
        $capacity = $product->roomCapacity();
        $counts   = self::blockedCounts($product);

        foreach (self::daysBetween($start, $end) as $day) {
            if ($rooms > 0) {
                $counts[$day] = min($capacity, ($counts[$day] ?? 0) + $rooms);
            } else {
                unset($counts[$day]);
            }
        }

        ksort($counts);
        $ranges = [];
        $run    = null;

        foreach ($counts as $day => $count) {
            if ($run && $run['count'] === $count && Carbon::parse($run['end'])->addDay()->toDateString() === $day) {
                $run['end'] = $day;
                continue;
            }
            if ($run) {
                $ranges[] = self::range($run, $capacity);
            }
            $run = ['start' => $day, 'end' => $day, 'count' => $count];
        }
        if ($run) {
            $ranges[] = self::range($run, $capacity);
        }

        return $ranges;
    }

    private static function range(array $run, int $capacity): array
    {
        return $run['count'] >= $capacity
            ? ['start' => $run['start'], 'end' => $run['end']]
            : ['start' => $run['start'], 'end' => $run['end'], 'rooms' => $run['count']];
    }

    /** @return array<int, string> */
    private static function daysBetween(string $start, string $end): array
    {
        $days = [];
        for ($d = Carbon::parse($start)->startOfDay(), $last = Carbon::parse($end)->startOfDay(); $d->lte($last); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    private static function saveRanges(Product $product, array $ranges): void
    {
        $config = is_array($product->room_config) ? $product->room_config : [];
        $config['blocked_ranges'] = $ranges;
        $product->update(['room_config' => $config]);

        app(SlotRealtimeService::class)->broadcastDailyBlocked((string) $product->id, $ranges);
    }
}
