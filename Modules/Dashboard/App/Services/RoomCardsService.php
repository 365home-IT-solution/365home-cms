<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class RoomCardsService
{
    // $days — số ngày hiển thị của lưới ngày×khung giờ (view "Lịch" ở Dashboard, ô nhập cạnh
    // carousel chi nhánh — xem routes/web.php admin.room-cards) — clamp lại 1 LẦN NỮA ở đây
    // (không chỉ ở route) vì getData() còn được gọi trực tiếp từ Dashboard::getViewData() (lần
    // render Blade đầu tiên, không qua route đó).
    public static function getData($user = null, int $days = 10): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

        $days = max(1, min(15, $days));

        $today        = Carbon::today();
        $now          = Carbon::now();
        $weekEnd      = Carbon::today()->addDays(30);
        $newThreshold = $now->copy()->subMinutes(60);

        $statusLabels = [
            'pending'   => 'Chờ xác nhận',
            'deposit'   => 'Đặt cọc',
            'paid'      => 'Đã thanh toán',
            'shipping'  => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã huỷ',
            'failed'    => 'Thất bại',
        ];
        $statusColors = [
            'pending'   => '#f59e0b',
            'deposit'   => '#d97757',
            'paid'      => '#10b981',
            'shipping'  => '#3b82f6',
            'completed' => '#8b5cf6',
            'cancelled' => '#ef4444',
            'failed'    => '#ef4444',
        ];

        $productQuery = Product::with(['categories.parent'])->where('is_activated', true);

        if ($user && ! $user->isSuperAdmin()) {
            // Product đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds chỉ thu hẹp thêm.
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                // Phòng KHÔNG gán chi nhánh nào (categories rỗng) không thuộc về bất kỳ ai —
                // luôn hiện (nhóm "Chưa phân loại") để không bị "biến mất" khỏi mọi tài khoản bị
                // giới hạn theo chi nhánh, thay vì âm thầm bị whereHas() loại bỏ hoàn toàn.
                $productQuery->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }

        $products   = $productQuery->orderBy('name')->get();
        $productIds = $products->pluck('id')->toArray();

        $allItems = OrderItem::with(['order'])
            ->whereIn('product_id', $productIds)
            ->where(function ($q) use ($today, $weekEnd) {
                $q->where(function ($q2) use ($today, $weekEnd) {
                    $q2->where(function ($q3) use ($today) {
                        $q3->whereDate('checkin_date', '<', $today)
                           ->whereDate('checkout_date', '>=', $today);
                    })
                    ->orWhereDate('checkin_date', $today)
                    ->orWhere(function ($q3) use ($today, $weekEnd) {
                        $q3->whereDate('checkin_date', '>', $today)
                           ->whereDate('checkin_date', '<=', $weekEnd);
                    });
                })
                ->orWhereHas('order', fn ($q2) => $q2->where('status', 'pending'));
            })
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'failed'])->where('exclude_from_stats', false))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('product_id');

        // Hoàn tiền đang chờ — dùng để hiện icon cảnh báo nhỏ trên thẻ phòng ở Dashboard, KHÔNG
        // giới hạn theo khoảng ngày hiển thị đơn ở trên (đơn có thể đã checkout từ lâu nhưng
        // khoản hoàn tiền vẫn chưa xử lý xong, staff vẫn cần thấy).
        $pendingRefundsByProduct = OrderItem::whereIn('product_id', $productIds)
            ->whereNotNull('product_id')
            ->whereHas('order', fn ($q) => $q->where('extra_refund_amount', '>', 0)->whereNull('extra_refund_paid_at'))
            ->with('order')
            ->get()
            ->groupBy('product_id')
            ->map(function ($items) {
                $order = $items->first()->order;
                return [
                    'order_id'   => $order->id,
                    'order_code' => $order->order_code ?? '—',
                    'buyer_name' => $order->buyer_name ?? 'Khách',
                    'amount'     => (int) $order->extra_refund_amount,
                ];
            });

        // View "Dải giờ" — lưới Ngày × Khung giờ giống hệt trang đặt phòng của khách (book.blade.php)
        // thay cho thanh 24h cũ. Nạp SẴN 1 LẦN toàn bộ khung giờ (RoomTimeSlot) của MỌI phòng kiểu
        // "khung giờ" (style 1) ở đây — tránh N+1 query nếu tự query riêng cho từng phòng bên dưới.
        // Phòng "đặt theo ngày" (style 2) không có khung giờ cố định nên không cần tra bảng này.
        $slotStyleProductIds = $products->filter(fn ($p) => (int) $p->styles === 1)->pluck('id')->toArray();
        $allRoomTimeSlots = RoomTimeSlot::whereIn('room_id', $slotStyleProductIds)
            ->whereNull('date')
            ->with(['timeSlot', 'promotions'])
            ->get()
            ->filter(fn (RoomTimeSlot $slot) => $slot->timeSlot !== null)
            ->groupBy('room_id');

        // Giữ chỗ real-time (TimeslotHoldService) — nạp 1 LẦN cho TOÀN BỘ room_time_slot_id liên
        // quan (mọi phòng), giống hệt cách allRoomTimeSlots ở trên tránh N+1 — view "Lịch"
        // (Dashboard::rcCalRenderGrid()) cần biết ô nào đang bị giữ để không cho 2 admin chọn
        // trùng, và ô "held_mine" (chính mình đang giữ, vd mới F5 lại trang) vẫn hiện lại đúng
        // trạng thái đã chọn dở.
        $allSlotIds = $allRoomTimeSlots->flatten()->pluck('id')->all();
        $holdsMap   = empty($allSlotIds)
            ? []
            : app(\App\Services\TimeslotHoldService::class)->getActiveHoldsMap($allSlotIds);
        $currentUserId = $user?->id;

        $rooms = $products->map(function ($product) use ($allItems, $pendingRefundsByProduct, $statusLabels, $statusColors, $newThreshold, $today, $now, $allRoomTimeSlots, $holdsMap, $currentUserId, $days) {
            $category   = $product->categories->first();
            $parent     = $category ? $category->parent : null;
            $branchName = $parent ? $parent->name : ($category ? $category->name : 'Chưa phân loại');
            $branchId   = $parent ? $parent->id : ($category ? $category->id : 0);

            $productItems = $allItems->get($product->id, collect());
            $hasNew       = $productItems->contains(fn ($item) => $item->order && $item->order->created_at >= $newThreshold);
            $latestTime   = $productItems->max(fn ($item) => $item->order?->created_at?->timestamp ?? 0);

            $isSlotStyle = (int) $product->styles === 1;

            $buildEntry = function ($item, $slotCount = null, $slotLabels = null) use ($statusLabels, $statusColors, $newThreshold, $today, $now, $isSlotStyle) {
                $order = $item->order;
                if (! $order) return null;
                $status       = $order->status ?? 'pending';
                $checkinDate  = $item->checkin_date;
                $checkoutDate = $item->checkout_date;

                if ($checkoutDate && $checkoutDate->lt($now)) {
                    $segment = 'overdue';
                } elseif ($checkinDate && $checkoutDate && $checkinDate->lte($now) && $checkoutDate->gte($now)) {
                    $segment = 'active';
                } elseif ($checkinDate && $checkinDate->isToday()) {
                    $segment = 'today';
                } else {
                    $segment = 'upcoming';
                }

                $t = fn ($dt) => $isSlotStyle ? '' : (' ' . $dt->format('H:i'));

                if ($checkinDate) {
                    if ($checkinDate->isToday()) {
                        $checkinLabel = 'Hôm nay' . $t($checkinDate);
                    } elseif ($checkinDate->isTomorrow()) {
                        $checkinLabel = 'Ngày mai' . $t($checkinDate);
                    } else {
                        $checkinLabel = $checkinDate->format('d/m') . $t($checkinDate);
                    }
                } else {
                    $checkinLabel = '';
                }

                $checkoutLabel = $checkoutDate
                    ? ($checkoutDate->format('d/m') . $t($checkoutDate))
                    : '';

                return [
                    'order_id'     => $order->id,
                    'order_code'   => $order->order_code ?? '—',
                    'buyer_name'   => $order->buyer_name ?? 'Khách',
                    'buyer_phone'  => $order->buyer_phone ?? '',
                    'status'       => $status,
                    'status_label' => $statusLabels[$status] ?? $status,
                    'status_color' => $statusColors[$status] ?? '#94a3b8',
                    'checkin'      => $checkinLabel,
                    'checkout'     => $checkoutLabel,
                    // Mốc thời gian THẬT (unix seconds) — dùng để đặt vị trí đoạn trên thanh dải
                    // thời gian (view "Dải giờ"), 'checkin'/'checkout' ở trên chỉ là nhãn hiển thị
                    // (đã format "Hôm nay"/"Ngày mai"...), không đủ chính xác để tính vị trí %.
                    'checkin_ts'   => $checkinDate?->timestamp,
                    'checkout_ts'  => $checkoutDate?->timestamp,
                    'amount'       => $order->amount ?? 0,
                    'created_at'     => $order->created_at ? $order->created_at->diffForHumans() : '',
                    'created_at_fmt' => $order->created_at ? $order->created_at->format('d/m/Y H:i') : '',
                    'is_new'         => $order->created_at !== null && $order->created_at >= $newThreshold,
                    'segment'      => $segment,
                    'slot_count'   => $slotCount,
                    'slot_labels'  => $slotLabels,
                    'slot_ranges'  => [],
                    'deposit_room' => $order->deposit_room ?? '',
                    // Đơn có ghi chú (Order.description) — chỉ cờ boolean, KHÔNG trả nguyên nội
                    // dung ghi chú trong payload đổ hết mọi phòng/ngày này (poll định kỳ, có thể
                    // dài) — view "Lịch" (Dashboard::rcCalRenderGrid()) dùng cờ này để hiện badge
                    // tròn trên ô, nội dung thật lấy riêng qua GET .../quick-info khi bấm vào ô.
                    'has_note'     => filled($order->description),
                ];
            };

            if ($isSlotStyle) {
                $orders = $productItems
                    ->filter(fn ($item) => $item->order && $item->product_id !== null)
                    ->groupBy(fn ($item) => $item->order_id)
                    ->map(function ($groupItems) use ($buildEntry, $now, $product) {
                        $first     = $groupItems->first();
                        $slotCount = $groupItems->count();
                        $slotLabels = $groupItems
                            ->map(function ($i) {
                                if ($i->checkin_date && $i->checkout_date) {
                                    return $i->checkin_date->format('H:i') . ' - ' . $i->checkout_date->format('H:i');
                                }
                                return $i->slot_label;
                            })
                            ->filter()
                            ->unique()
                            ->join(', ');
                        $entry = $buildEntry($first, $slotCount, $slotLabels ?: null);
                        if ($entry) {
                            // Re-determine segment using ALL slots, not just the first
                            $anyActive = $groupItems->contains(
                                fn ($i) => $i->checkin_date && $i->checkout_date
                                    && $i->checkin_date->lte($now) && $i->checkout_date->gte($now)
                            );
                            $allOverdue = $groupItems->every(
                                fn ($i) => $i->checkout_date && $i->checkout_date->lt($now)
                            );
                            if ($anyActive) {
                                $entry['segment'] = 'active';
                            } elseif (! $allOverdue) {
                                $anyToday = $groupItems->contains(
                                    fn ($i) => $i->checkin_date && $i->checkin_date->isToday()
                                );
                                $entry['segment'] = $anyToday ? 'today' : 'upcoming';
                            }
                            // Build full datetime ranges for each slot (for popup display)
                            $entry['slot_ranges'] = $groupItems
                                ->filter(fn ($i) => $i->checkin_date && $i->checkout_date)
                                ->map(fn ($i) => [
                                    'start'    => $i->checkin_date->format('d/m/Y H:i'),
                                    'end'      => $i->checkout_date->format('d/m/Y H:i'),
                                    'start_ts' => $i->checkin_date->timestamp,
                                    'end_ts'   => $i->checkout_date->timestamp,
                                ])
                                ->values()
                                ->toArray();
                            // checkin_ts/checkout_ts (dùng cho view "Dải giờ") phải bao trọn TOÀN
                            // BỘ các khung giờ của đơn (từ khung sớm nhất tới khung muộn nhất) —
                            // buildEntry() ở trên chỉ lấy từ item ĐẦU TIÊN nên nếu đơn có 2+ khung
                            // giờ (vd 8h30-11h20 và 11h50-14h40), thanh dải giờ trước đây bị cắt
                            // cụt chỉ còn khung đầu. 2 khung của CÙNG 1 khách coi là 1 lượt lưu trú
                            // liên tục, nối liền thành 1 đoạn duy nhất, không tách rời trên thanh.
                            if (! empty($entry['slot_ranges'])) {
                                $entry['checkin_ts']  = min(array_column($entry['slot_ranges'], 'start_ts'));
                                $entry['checkout_ts'] = max(array_column($entry['slot_ranges'], 'end_ts'));
                            }
                            // Lấy 'amount' thực tế từ DB (tổng thực thu hiện tại — đã gồm KM +
                            // chiết khấu + phụ thu phát sinh nếu có; 'full_amount' chỉ là tổng giá
                            // gốc cố định lúc đặt, không phản ánh phụ phí phát sinh sau này)
                            $entry['amount'] = (int)($first->order?->amount ?? 0);
                        }
                        return $entry;
                    })
                    ->filter()
                    ->values()
                    ->toArray();
            } else {
                // Daily/monthly: nhóm theo order_id, hiển thị 1 card per đơn
                $orders = $productItems
                    ->filter(fn ($item) => $item->order && $item->product_id !== null)
                    ->groupBy(fn ($item) => $item->order_id)
                    ->map(function ($groupItems) use ($buildEntry, $now) {
                        // Sắp xếp theo checkin để lấy đúng first/last
                        $sorted = $groupItems->sortBy(fn ($i) => $i->checkin_date?->timestamp ?? 0);
                        $first  = $sorted->first();
                        $last   = $sorted->last();

                        // Dùng item tổng hợp: checkin từ item đầu, checkout từ item cuối
                        $proxy = clone $first;
                        $proxy->checkout_date = $last->checkout_date;

                        $entry = $buildEntry($proxy);
                        if ($entry) {
                            // Segment dựa trên toàn bộ group
                            $anyActive = $groupItems->contains(
                                fn ($i) => $i->checkin_date && $i->checkout_date
                                    && $i->checkin_date->lte($now) && $i->checkout_date->gte($now)
                            );
                            $allOverdue = $groupItems->every(
                                fn ($i) => $i->checkout_date && $i->checkout_date->lt($now)
                            );
                            if ($anyActive) {
                                $entry['segment'] = 'active';
                            } elseif (! $allOverdue) {
                                $anyToday = $groupItems->contains(
                                    fn ($i) => $i->checkin_date && $i->checkin_date->isToday()
                                );
                                $entry['segment'] = $anyToday ? 'today' : 'upcoming';
                            }
                            $entry['amount'] = (int)($first->order?->amount ?? 0);
                        }
                        return $entry;
                    })
                    ->filter()
                    ->values()
                    ->toArray();
            }

            $segOrd = ['active' => 0, 'today' => 1, 'upcoming' => 2, 'overdue' => 3];
            usort($orders, fn ($a, $b) => ($segOrd[$a['segment']] ?? 2) <=> ($segOrd[$b['segment']] ?? 2));

            $activeCount   = count(array_filter($orders, fn ($o) => $o['segment'] === 'active'));
            $todayCount    = count(array_filter($orders, fn ($o) => $o['segment'] === 'today'));
            $upcomingCount = count(array_filter($orders, fn ($o) => $o['segment'] === 'upcoming'));
            $overdueCount  = count(array_filter($orders, fn ($o) => $o['segment'] === 'overdue'));

            // Hold của KHÁCH (khác hẳn $holdsMap ở trên — đó là hold của ADMIN, bảng DB
            // timeslot_holds). Khách lưu ở Cache riêng theo từng phòng (TimeSlotHoldController,
            // không FK được tới users vì khách vãng lai không có tài khoản) nên phải gọi 1 lần/
            // phòng — không batch được như $holdsMap, nhưng đây chỉ là Cache::get (rẻ) và chỉ gọi
            // cho phòng kiểu khung giờ (style 1) đang hiển thị trên Dashboard, không phải mọi
            // phòng trong hệ thống.
            $customerHoldsMap = [];
            if ($isSlotStyle) {
                foreach (\App\Http\Controllers\Api\TimeSlotHoldController::getActiveHolds((string) $product->id) as $h) {
                    // Cache lưu 'date' dạng d-m-Y (khớp GET .../time-slots — xem docblock
                    // TimeSlotHoldController), trong khi $dates['iso'] của lưới này là Y-m-d —
                    // phải đổi định dạng NGAY ĐÂY để khớp key 'timeslot_id|Y-m-d' dùng bên dưới.
                    $isoDate = Carbon::createFromFormat('d-m-Y', $h['date'])->toDateString();
                    $customerHoldsMap[$h['timeslot_id'] . '|' . $isoDate] = true;
                }
            }

            $timeslotGrid = self::buildTimeslotGrid(
                $product,
                $orders,
                $today,
                $allRoomTimeSlots->get($product->id, collect()),
                $holdsMap,
                $currentUserId,
                $days,
                $customerHoldsMap
            );

            return [
                'product_id'          => $product->id,
                'room_name'           => $product->name,
                'branch'              => $branchName,
                'branch_id'           => $branchId,
                'styles'              => (int) $product->styles,
                'orders'              => $orders,
                'timeslot_grid'       => $timeslotGrid,
                'count'               => count($orders),
                'active_count'        => $activeCount,
                'today_count'         => $todayCount,
                'upcoming_count'      => $upcomingCount,
                'overdue_count'       => $overdueCount,
                'has_new'             => $hasNew,
                'latest_time'         => $latestTime,
                'housekeeping_status' => $product->housekeeping_status ?? 'available',
                'pending_refund'      => $pendingRefundsByProduct->get($product->id),
            ];
        })->toArray();

        usort($rooms, function ($a, $b) {
            if ($b['active_count'] !== $a['active_count']) return $b['active_count'] <=> $a['active_count'];
            if ($b['today_count']  !== $a['today_count'])  return $b['today_count']  <=> $a['today_count'];
            if ($b['has_new']      !== $a['has_new'])      return $b['has_new']      <=> $a['has_new'];
            if ($b['count']        !== $a['count'])        return $b['count']        <=> $a['count'];
            return ($b['latest_time'] ?? 0) <=> ($a['latest_time'] ?? 0);
        });

        $branchMap = [];
        foreach ($rooms as $room) {
            $key = $room['branch'];
            if (! isset($branchMap[$key])) {
                $branchMap[$key] = [
                    'name'           => $key,
                    'branch_id'      => $room['branch_id'],
                    'order_count'    => 0,
                    'new_count'      => 0,
                    'active_count'   => 0,
                    'today_count'    => 0,
                    'upcoming_count' => 0,
                    'overdue_count'  => 0,
                ];
            }
            $branchMap[$key]['order_count']    += $room['count'];
            $branchMap[$key]['active_count']   += $room['active_count'];
            $branchMap[$key]['today_count']    += $room['today_count'];
            $branchMap[$key]['upcoming_count'] += $room['upcoming_count'];
            $branchMap[$key]['overdue_count']  += $room['overdue_count'];
            if ($room['has_new']) {
                $branchMap[$key]['new_count']++;
            }
        }

        $branches = array_values($branchMap);
        usort($branches, function ($a, $b) {
            if ($b['active_count'] !== $a['active_count']) return $b['active_count'] <=> $a['active_count'];
            if ($b['new_count']    !== $a['new_count'])    return $b['new_count']    <=> $a['new_count'];
            return $b['order_count'] <=> $a['order_count'];
        });

        return [
            'branches'       => $branches,
            'rooms'          => $rooms,
            'total_rooms'    => count($rooms),
            'total_orders'   => array_sum(array_column($rooms, 'count')),
            'total_active'   => array_sum(array_column($rooms, 'active_count')),
            'total_today'    => array_sum(array_column($rooms, 'today_count')),
            'total_upcoming' => array_sum(array_column($rooms, 'upcoming_count')),
            'total_overdue'  => array_sum(array_column($rooms, 'overdue_count')),
            // Mốc 00:00-24:00 hôm nay (unix seconds) — dùng chung cho view "Dải giờ" để đặt vị trí
            // đoạn/đường "hiện tại" trên thanh, đảm bảo blade (load lần đầu) và JS (polling) tính
            // trùng khớp nhau.
            'today_start_ts' => $today->copy()->startOfDay()->timestamp,
            'today_end_ts'   => $today->copy()->endOfDay()->timestamp,
        ];
    }

    // View "Dải giờ" — lưới Ngày × Khung giờ CHO ĐÚNG 1 PHÒNG này, cùng cấu trúc hàng/cột với
    // trang đặt phòng của khách (book.blade.php/_desktop-grid.blade.php: hàng = khung giờ cố định
    // của phòng, cột = ngày) — khác ở chỗ đây là xem TÌNH TRẠNG (đã đặt/còn trống), không phải màn
    // hình CHỌN khung giờ để đặt, nên không cần tính giá/khuyến mãi từng ô. Phòng "đặt theo ngày"
    // (style 2) không có khung giờ cố định — chỉ có đúng 1 "hàng" duy nhất ứng với cả ngày.
    //
    // $days: số cột ngày hiển thị — 7 ngày (tuần hiện tại) đủ để thấy tình trạng gần mà vẫn gọn
    // trong 1 thẻ phòng, không tính lại nếu admin muốn xem xa hơn (đã có view "Danh sách" liệt kê
    // đủ mọi đơn upcoming cho việc đó rồi).
    // $currentUserId: kiểu string, KHÔNG phải int — User.id trong app này là UUID.
    private static function buildTimeslotGrid(Product $product, array $orders, Carbon $today, $roomTimeSlots, array $holdsMap = [], ?string $currentUserId = null, int $days = 10, array $customerHoldsMap = []): array
    {
        $isSlotStyle = (int) $product->styles === 1;
        $now         = Carbon::now();

        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $today->copy()->addDays($i);
            $dates[] = [
                'iso'      => $d->format('Y-m-d'),
                'label'    => $d->format('d/m'),
                'dow'      => ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][$d->dayOfWeek],
                'is_today' => $d->isSameDay($today),
            ];
        }

        if ($isSlotStyle) {
            $rows = $roomTimeSlots
                ->sortBy(fn (RoomTimeSlot $slot) => $slot->timeSlot->start_time)
                ->values()
                ->map(fn (RoomTimeSlot $slot) => [
                    'id'          => (string) $slot->id,
                    // ID của TimeSlot dùng chung (KHÁC 'id' ở trên — id của RoomTimeSlot riêng
                    // phòng này) — cần để khớp với khách hàng holds phía Cache (xem
                    // TimeSlotHoldController, key theo timeslot_id chứ không phải room_time_slot
                    // id) khi tính 'held_customer' bên dưới.
                    'timeslot_id' => $slot->timeslot_id,
                    'label'      => $slot->timeSlot->label
                        ?: (substr($slot->timeSlot->start_time, 0, 5) . '-' . substr($slot->timeSlot->end_time, 0, 5)),
                    'start_time' => $slot->timeSlot->start_time,
                    'end_time'   => $slot->timeSlot->end_time,
                    'over_night' => (bool) ($slot->timeSlot->over_night ?? false),
                    // Khoá dài hạn (BlockTimeslotModal::saveBlock() style=1 — ĐÚNG field
                    // book/_slot-cell.blade.php phía khách đang đọc) — mảng ngày Y-m-d bị khoá
                    // của riêng khung giờ (room_time_slot) này, đọc thẳng settings JSON, không
                    // cần query/join thêm.
                    'blocked_dates' => $slot->settings['blocked_dates'] ?? [],
                    // Khung giờ ĐANG có khuyến mãi hiệu lực (gắn qua popup "Giá phòng" —
                    // rcCalSavePricing()/routes/web.php admin.rooms.pricing-info) — dùng để tô
                    // hiệu ứng viền neon các ô trống thuộc khung giờ này (view "Lịch"). Chỉ tính
                    // khuyến mãi is_active=true VÀ hôm nay nằm trong [start_at, end_at] (null =
                    // không giới hạn phía đó), giống đúng luật lọc 'available_promotions' ở route
                    // pricing-info.
                    'has_discount' => $slot->promotions->contains(function ($p) use ($today) {
                        if (! $p->is_active) return false;
                        if ($p->start_at && $today->lt($p->start_at)) return false;
                        if ($p->end_at && $today->gt($p->end_at)) return false;
                        return true;
                    }),
                ])
                ->toArray();
        } else {
            $rows = [[
                'id'            => 'day',
                'label'         => 'Cả ngày',
                'start_time'    => null,
                'end_time'      => null,
                'over_night'    => false,
                'blocked_dates' => [],
            ]];
        }

        // Gộp mọi khoảng thời gian đã có khách vào 1 danh sách phẳng để so khớp — ưu tiên
        // 'slot_ranges' (đã tách ĐÚNG từng khung giờ riêng của đơn) nếu có, không thì dùng
        // checkin_ts/checkout_ts gộp (đơn "đặt theo ngày" không có slot_ranges).
        $ranges = [];
        foreach ($orders as $o) {
            if (! empty($o['slot_ranges'])) {
                foreach ($o['slot_ranges'] as $r) {
                    $ranges[] = ['start' => $r['start_ts'], 'end' => $r['end_ts'], 'order' => $o];
                }
            } elseif (! empty($o['checkin_ts']) && ! empty($o['checkout_ts'])) {
                $ranges[] = ['start' => $o['checkin_ts'], 'end' => $o['checkout_ts'], 'order' => $o];
            }
        }

        $cells = [];
        foreach ($rows as $row) {
            foreach ($dates as $date) {
                if ($isSlotStyle) {
                    $start = Carbon::parse($date['iso'] . ' ' . $row['start_time']);
                    $end   = Carbon::parse($date['iso'] . ' ' . $row['end_time']);
                    if ($row['over_night'] || $end->lessThanOrEqualTo($start)) {
                        $end->addDay();
                    }
                } else {
                    $start = Carbon::parse($date['iso'])->startOfDay();
                    $end   = Carbon::parse($date['iso'])->endOfDay();
                }

                $match    = null;
                $matchEnd = null;
                foreach ($ranges as $r) {
                    if ($start->timestamp < $r['end'] && $end->timestamp > $r['start']) {
                        $match    = $r['order'];
                        // 'end' — checkout_ts THẬT của order_item khớp (từ slot_ranges nếu có, xem
                        // vòng lặp build $ranges bên dưới), KHÔNG phải giờ kết thúc lý thuyết của
                        // khung giờ ($end biến ngoài) — để so "quá giờ" đúng theo dữ liệu đơn thật.
                        $matchEnd = $r['end'];
                        break;
                    }
                }

                // 'kind' PHÂN BIỆT rõ 5 trạng thái ô — 'status' (nếu có) chỉ mang nghĩa trạng thái
                // ĐƠN HÀNG (paid/pending/...) khi kind='booked', KHÔNG dùng lẫn cho blocked/held/
                // free (view "Lịch" — Dashboard::rcCalRenderGrid() — cần phân biệt để biết ô nào
                // bấm chọn được). Ô đã đặt LUÔN ưu tiên trên hết, sau đó tới khoá dài hạn
                // (blocked_dates — BlockTimeslotModal), rồi tới giữ chỗ real-time (TimeslotHold —
                // CHỈ áp dụng cho phòng theo khung giờ, phòng đặt-theo-ngày không có khái niệm
                // giữ-từng-ô này), còn lại là free (bấm chọn được).
                if ($match) {
                    // Quá giờ (checkout_date của order_item đã trôi qua so với hiện tại) — ô hiện
                    // "Hết giờ" thay vì "Đã đặt" (rcCalRenderGrid() trong _scripts.blade.php), báo
                    // hiệu khách đã hết thời gian thuê nhưng đơn có thể chưa được cập nhật trạng
                    // thái — CHỈ có ý nghĩa với ngày hôm nay/quá khứ trong phạm vi hiển thị.
                    $isOverdue = $matchEnd !== null && $now->timestamp > $matchEnd;

                    $cells[$row['id'] . '|' . $date['iso']] = [
                        'kind'         => 'booked',
                        'status'       => $match['status'],
                        'status_label' => $match['status_label'],
                        'color'        => $match['status_color'],
                        'order_id'     => $match['order_id'],
                        'order_code'   => $match['order_code'],
                        'buyer_name'   => $match['buyer_name'],
                        'buyer_phone'  => $match['buyer_phone'],
                        'checkin'      => $match['checkin'],
                        'checkout'     => $match['checkout'],
                        'amount'       => $match['amount'],
                        'has_note'     => $match['has_note'] ?? false,
                        'is_overdue'   => $isOverdue,
                    ];
                    continue;
                }

                if ($isSlotStyle && in_array($date['iso'], $row['blocked_dates'], true)) {
                    $cells[$row['id'] . '|' . $date['iso']] = ['kind' => 'blocked'];
                    continue;
                }

                $hold = $isSlotStyle ? ($holdsMap[$row['id'] . '|' . $date['iso']] ?? null) : null;
                if ($hold) {
                    $isMine = $currentUserId && (string) $hold->user_id === $currentUserId;
                    $cells[$row['id'] . '|' . $date['iso']] = [
                        'kind'     => $isMine ? 'held_mine' : 'held_other',
                        'held_by'  => $hold->user->fullname ?? $hold->user->email ?? 'Admin khác',
                    ];
                    continue;
                }

                // Khách đang chọn ô này ở trang đặt phòng (chưa xác nhận đặt) — khớp theo
                // 'timeslot_id' (ID TimeSlot dùng chung), KHÔNG phải $row['id'] (ID RoomTimeSlot
                // riêng phòng này, khác bảng). Đặt SAU held_mine/held_other (admin) vì nếu admin
                // đang giữ ô này thì ưu tiên hiện trạng thái admin, không hiện đè trạng thái khách.
                if ($isSlotStyle && isset($customerHoldsMap[$row['timeslot_id'] . '|' . $date['iso']])) {
                    $cells[$row['id'] . '|' . $date['iso']] = ['kind' => 'held_customer'];
                    continue;
                }

                $cells[$row['id'] . '|' . $date['iso']] = ['kind' => 'free'];
            }
        }

        return [
            'is_slot_style' => $isSlotStyle,
            'dates'         => $dates,
            'rows'          => $rows,
            'cells'         => $cells,
        ];
    }

    /**
     * Tính tổng tiền cho nhóm khung giờ của 1 phòng trong 1 đơn hàng,
     * áp dụng bulk_discount_rules và phụ thu từ room_config.
     */
    private static function computeSlotAmount($groupItems, Product $product): int
    {
        $cfg     = $product->room_config ?? [];
        $maxFree = (int)($cfg['max_free_guests'] ?? 2);
        $feeEach = (int)($cfg['extra_guest_fee'] ?? 0);

        $slotCount       = $groupItems->count();
        $bulkDiscountPct = 0;

        if ($slotCount >= 2) {
            $rules = $product->bulk_discount_rules ?? [];
            usort($rules, fn ($a, $b) => (int)($b['slots'] ?? 0) - (int)($a['slots'] ?? 0));
            foreach ($rules as $rule) {
                if ($slotCount >= (int)($rule['slots'] ?? 0)) {
                    $bulkDiscountPct = (float)($rule['discount'] ?? 0);
                    break;
                }
            }
        }

        $total = 0;
        foreach ($groupItems as $item) {
            $basePrice = (float)($item->price ?? 0);
            if ($bulkDiscountPct > 0) {
                $basePrice = round($basePrice * (1 - $bulkDiscountPct / 100));
            }
            $guestCount = (int)($item->guest_count ?? 1);
            $extraFee   = $guestCount > $maxFree ? ($guestCount - $maxFree) * $feeEach : 0;
            if ((float)($item->extra_fee ?? 0) > $extraFee) {
                $extraFee = (float)$item->extra_fee;
            }
            $total += $basePrice + $extraFee;
        }

        return (int)$total;
    }
}
