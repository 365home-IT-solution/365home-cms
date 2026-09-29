<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;

/**
 * Widget "LỄ TÂN" (Tổng quan admin + app): 4 chỉ số Đã nhận / Đã trả / Có khách / Ở quá giờ — CẢ số
 * đếm (OverviewService::frontDesk()) LẪN danh sách chi tiết khi bấm vào từng thẻ
 * (GET /api/admin/dashboard/front-desk/{type}) đều đi qua CÙNG các query builder ở đây, nên số trên
 * thẻ luôn bằng đúng tổng số dòng của danh sách chi tiết. "Cần dọn" không nằm ở đây — đọc thẳng
 * products.housekeeping_status (xem OverviewService::frontDesk() + GET /api/admin/products
 * ?front_desk_status=needs_cleaning).
 *
 *  - checked_in  (Đã nhận)   : đơn có orders.checked_in_at rơi vào kỳ chọn — 1 dòng / 1 đơn.
 *  - checked_out (Đã trả)    : đơn có ít nhất 1 order_item có lịch trả phòng (checkout_date) rơi vào
 *                              kỳ chọn — 1 dòng / 1 đơn.
 *  - has_guest   (Có khách)  : phòng đang trong khoảng lưu trú TẠI THỜI ĐIỂM GỌI API — 1 dòng / 1
 *                              phòng (ảnh chụp tức thời, không theo kỳ).
 *  - overstay    (Ở quá giờ) : phòng có lượt lưu trú hết giờ TRONG HÔM NAY (checkout_date đã qua) mà
 *                              đơn đó CHƯA trả phòng (checked_out_at null) và phòng hiện KHÔNG có lượt
 *                              lưu trú nào khác đang diễn ra (vd khung giờ nối tiếp của cùng đơn) —
 *                              1 dòng / 1 phòng (ảnh chụp tức thời, không theo kỳ).
 *
 * Mọi chỉ số bỏ qua đơn exclude_from_stats=true và đơn đã chết (DEAD_ORDER_STATUSES) — đơn thanh
 * toán lỗi/huỷ/hoàn tiền không phải khách thật đang ở hay đã nhận/trả phòng.
 */
class FrontDeskService
{
    public const TYPES = ['checked_in', 'checked_out', 'has_guest', 'overstay'];

    public const TYPE_LABELS = [
        'checked_in'  => 'Đã nhận',
        'checked_out' => 'Đã trả',
        'has_guest'   => 'Có khách',
        'overstay'    => 'Ở quá giờ',
    ];

    // 2 loại là ảnh chụp tức thời (không đổi theo kỳ chọn), 2 loại còn lại lọc theo kỳ.
    public const SNAPSHOT_TYPES = ['has_guest', 'overstay'];

    private const DEAD_ORDER_STATUSES = ['failed', 'cancelled', 'cancelled_payment', 'refunded'];

    private const STYLES_LABELS = [1 => 'Theo khung giờ', 2 => 'Theo ngày'];

    /** Số đếm cho 4 thẻ — cùng query với details(). */
    public static function counts(array $productIds, Carbon $start, Carbon $end, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'checked_in_count'  => static::checkedInOrdersQuery($productIds, $start, $end)->count(),
            'checked_out_count' => static::checkedOutOrdersQuery($productIds, $start, $end)->count(),
            'occupied_rooms'    => count(static::occupiedProductIds($productIds, $now)),
            'overstay_rooms'    => count(static::overstayItems($productIds, $now)->unique('product_id')),
        ];
    }

    /**
     * Danh sách chi tiết của 1 thẻ, đã phân trang. $search lọc theo mã đơn / tên / SĐT khách / tên phòng.
     *
     * @return array{paginator: LengthAwarePaginator, summary: array}
     */
    public static function details(
        string $type,
        array $productIds,
        Carbon $start,
        Carbon $end,
        ?string $search,
        int $perPage,
        int $page,
        ?Carbon $now = null
    ): array {
        $now ??= Carbon::now();

        return match ($type) {
            'checked_in'  => static::orderDetails(static::checkedInOrdersQuery($productIds, $start, $end)->orderByDesc('checked_in_at'), $type, $productIds, $search, $perPage, $page, $now),
            'checked_out' => static::orderDetails(static::checkedOutOrdersQuery($productIds, $start, $end)->orderByDesc(static::latestCheckoutSubquery()), $type, $productIds, $search, $perPage, $page, $now),
            'has_guest'   => static::roomDetails(static::hasGuestItems($productIds, $now), $type, $productIds, $search, $perPage, $page, $now),
            'overstay'    => static::roomDetails(static::overstayItems($productIds, $now), $type, $productIds, $search, $perPage, $page, $now),
        };
    }

    // ── Query dùng chung cho counts()/details() ─────────────────────────────────────────────

    private static function liveOrderScope(Builder $q): Builder
    {
        return $q->where('exclude_from_stats', false)->whereNotIn('status', self::DEAD_ORDER_STATUSES);
    }

    private static function checkedInOrdersQuery(array $productIds, Carbon $start, Carbon $end): Builder
    {
        return static::liveOrderScope(Order::query())
            ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereBetween('checked_in_at', [$start, $end]);
    }

    private static function checkedOutOrdersQuery(array $productIds, Carbon $start, Carbon $end): Builder
    {
        return static::liveOrderScope(Order::query())
            ->whereHas('items', fn ($q) => $q
                ->whereIn('product_id', $productIds)
                ->whereBetween('checkout_date', [$start, $end]));
    }

    private static function liveItemsQuery(array $productIds): Builder
    {
        return OrderItem::query()
            ->whereIn('product_id', $productIds)
            ->whereHas('order', fn ($o) => static::liveOrderScope($o));
    }

    /** Lượt lưu trú đang diễn ra tại $now. */
    private static function hasGuestItems(array $productIds, Carbon $now): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        return static::liveItemsQuery($productIds)
            ->where('checkin_date', '<=', $now)
            ->where('checkout_date', '>=', $now)
            ->orderBy('checkout_date')
            ->get();
    }

    private static function occupiedProductIds(array $productIds, Carbon $now): array
    {
        return static::hasGuestItems($productIds, $now)->pluck('product_id')->unique()->values()->all();
    }

    /** Lượt lưu trú hết giờ trong hôm nay nhưng đơn chưa trả phòng và phòng không còn ai đang ở. */
    private static function overstayItems(array $productIds, Carbon $now): Collection
    {
        if (empty($productIds)) {
            return collect();
        }

        $occupied = static::occupiedProductIds($productIds, $now);

        return OrderItem::query()
            ->whereIn('product_id', array_values(array_diff($productIds, $occupied)))
            ->whereHas('order', fn ($o) => static::liveOrderScope($o)->whereNull('checked_out_at'))
            ->where('checkin_date', '<=', $now)
            ->where('checkout_date', '>=', $now->copy()->startOfDay())
            ->where('checkout_date', '<', $now)
            // Trễ nhất trước — unique('product_id') bên dưới giữ lượt hết giờ GẦN NHẤT của mỗi phòng.
            ->orderByDesc('checkout_date')
            ->get();
    }

    private static function latestCheckoutSubquery(): Builder
    {
        return OrderItem::query()
            ->selectRaw('MAX(checkout_date)')
            ->whereColumn('order_items.order_id', 'orders.id');
    }

    // ── Dựng danh sách ────────────────────────────────────────────────────────────────────

    private static function orderDetails(Builder $query, string $type, array $productIds, ?string $search, int $perPage, int $page, Carbon $now): array
    {
        if ($search !== null && $search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('order_code', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%")
                    ->orWhere('buyer_phone', 'like', "%{$search}%")
                    ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $totalSlots = OrderItem::query()
            ->whereIn('order_id', (clone $query)->reorder()->select('id'))
            ->whereIn('product_id', $productIds)
            ->count();

        $paginator = $query
            ->with(['items.product.categories:id,name,slug,parent_id', 'category:id,name,slug'])
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(fn (Order $order) => static::toRow($order, $type, $productIds, $now));

        return ['paginator' => $paginator, 'summary' => ['total' => $paginator->total(), 'total_slots' => $totalSlots]];
    }

    private static function roomDetails(Collection $items, string $type, array $productIds, ?string $search, int $perPage, int $page, Carbon $now): array
    {
        $items->load(['order.items.product.categories:id,name,slug,parent_id', 'order.category:id,name,slug', 'product']);

        $totalSlots = $items->count();

        // 1 dòng / 1 phòng — has_guest: lượt sắp hết giờ nhất (đã sort checkout_date tăng dần);
        // overstay: lượt hết giờ gần nhất (đã sort giảm dần).
        $rows = $items->unique('product_id')
            ->map(fn (OrderItem $item) => static::toRow($item->order, $type, $productIds, $now, $item))
            ->values();

        if ($type === 'overstay') {
            $rows = $rows->sortByDesc('overdue_minutes')->values();
        }

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $rows   = $rows->filter(fn (array $row) => collect([$row['order_code'], $row['guest']['name'], $row['guest']['phone'], $row['room']['name']])
                ->contains(fn ($v) => $v !== null && str_contains(mb_strtolower((string) $v), $needle)))
                ->values();
        }

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return ['paginator' => $paginator, 'summary' => ['total' => $rows->count(), 'total_slots' => $totalSlots]];
    }

    /**
     * 1 dòng chi tiết. $focusItem = lượt lưu trú khiến dòng này lọt vào danh sách (has_guest/
     * overstay) — phòng hiển thị lấy theo lượt đó; checked_in/checked_out lấy phòng của order_item
     * đầu tiên thuộc phạm vi xem của user.
     */
    private static function toRow(Order $order, string $type, array $productIds, Carbon $now, ?OrderItem $focusItem = null): array
    {
        $items = $order->items->sortBy('checkin_date')->values();

        $roomItem = $focusItem ?? $items->first(fn (OrderItem $i) => in_array($i->product_id, $productIds, true)) ?? $items->first();
        $product  = $roomItem?->product;

        $category = $product?->categories->first();
        $branch   = $category?->parent ?? $category;

        $checkinAt  = $items->pluck('checkin_date')->filter()->min();
        $checkoutAt = $items->pluck('checkout_date')->filter()->max();

        // Mốc giờ của riêng lượt đang xét (has_guest/overstay) — đơn nhiều khung giờ không nối liền
        // thì checkout của CẢ đơn có thể khác giờ trả của lượt đang ở/đang quá giờ.
        $focusCheckout = $focusItem?->checkout_date ?? $checkoutAt;

        return [
            'order_id'             => $order->id,
            'order_code'           => $order->order_code,
            'room'                 => $product ? [
                'id'                  => $product->id,
                'name'                => $product->name,
                'styles'              => (int) $product->styles,
                'styles_label'        => self::STYLES_LABELS[(int) $product->styles] ?? null,
                'housekeeping_status' => $product->housekeeping_status ?? 'available',
            ] : null,
            'branch'               => $order->category
                ? ['id' => $order->category->id, 'name' => $order->category->name, 'slug' => $order->category->slug]
                : ($branch ? ['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug] : null),
            'guest'                => [
                'name'        => $order->buyer_name,
                'phone'       => $order->buyer_phone,
                'email'       => $order->buyer_email,
                'guest_count' => (int) ($order->guest_count ?? 1),
            ],
            'booking_type'         => $product && (int) $product->styles === 2 ? 'daily' : 'slot',
            'checkin_at'           => $checkinAt,
            'checkout_at'          => $checkoutAt,
            'current_slot'         => $focusItem ? static::slotRow($focusItem) : null,
            'slots'                => $items->map(fn (OrderItem $i) => static::slotRow($i))->all(),
            'slot_count'           => $items->count(),
            'checked_in_at'        => $order->checked_in_at,
            'checked_out_at'       => $order->checked_out_at,
            'order_status'         => $order->order_status ?? 'pending',
            'order_status_label'   => $order->order_status_label,
            'status'               => $order->status,
            'payment_status'       => $order->payment_status,
            'payment_status_label' => $order->payment_status_label,
            'payment_method'       => $order->payment_method,
            'total_amount'         => (int) ($order->full_amount ?? $order->amount ?? 0),
            'deposit_amount'       => $order->money_deposit !== null ? (int) $order->money_deposit : null,
            'remaining_minutes'    => $type === 'has_guest' && $focusCheckout ? (int) max(0, $now->diffInMinutes($focusCheckout, false)) : null,
            'overdue_minutes'      => $type === 'overstay' && $focusCheckout ? (int) max(0, $focusCheckout->diffInMinutes($now, false)) : null,
            'unlock_anytime'       => (bool) $order->unlock_anytime,
            'note_for_admin'       => $order->note_for_admin,
            'created_at'           => $order->created_at,
        ];
    }

    private static function slotRow(OrderItem $item): array
    {
        return [
            'order_item_id' => $item->id,
            'room_id'       => $item->product_id,
            'room_name'     => $item->product?->name ?? $item->name,
            'slot_label'    => $item->slot_label,
            'checkin_at'    => $item->checkin_date,
            'checkout_at'   => $item->checkout_date,
            'guest_count'   => $item->guest_count !== null ? (int) $item->guest_count : null,
        ];
    }
}
