<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Services;

use Carbon\Carbon;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

class RoomCardsService
{
    public static function getData($user = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }

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

        $empty = ['branches' => [], 'rooms' => [], 'total_rooms' => 0, 'total_orders' => 0,
                  'total_active' => 0, 'total_today' => 0, 'total_upcoming' => 0, 'total_overdue' => 0];

        $productQuery = Product::with(['categories.parent'])->where('is_activated', true);

        if ($user && ! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (empty($allowedCategoryIds)) {
                return $empty;
            }
            $productQuery->whereHas('categories', function ($q) use ($allowedCategoryIds) {
                $q->whereIn('categories.id', $allowedCategoryIds);
            });
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

        $rooms = $products->map(function ($product) use ($allItems, $statusLabels, $statusColors, $newThreshold, $today, $now) {
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
                    'amount'       => $order->full_amount ?? 0,
                    'created_at'     => $order->created_at ? $order->created_at->diffForHumans() : '',
                    'created_at_fmt' => $order->created_at ? $order->created_at->format('d/m/Y H:i') : '',
                    'is_new'         => $order->created_at !== null && $order->created_at >= $newThreshold,
                    'segment'      => $segment,
                    'slot_count'   => $slotCount,
                    'slot_labels'  => $slotLabels,
                    'slot_ranges'  => [],
                    'deposit_room' => $order->deposit_room ?? '',
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
                            // Lấy full_amount thực tế từ DB (đã gồm KM + chiết khấu + phụ thu)
                            $entry['amount'] = (int)($first->order?->full_amount ?? 0);
                        }
                        return $entry;
                    })
                    ->filter()
                    ->values()
                    ->toArray();
            } else {
                $orders = $productItems->map(fn ($item) => $buildEntry($item))->filter()->values()->toArray();
            }

            $segOrd = ['active' => 0, 'today' => 1, 'upcoming' => 2, 'overdue' => 3];
            usort($orders, fn ($a, $b) => ($segOrd[$a['segment']] ?? 2) <=> ($segOrd[$b['segment']] ?? 2));

            $activeCount   = count(array_filter($orders, fn ($o) => $o['segment'] === 'active'));
            $todayCount    = count(array_filter($orders, fn ($o) => $o['segment'] === 'today'));
            $upcomingCount = count(array_filter($orders, fn ($o) => $o['segment'] === 'upcoming'));
            $overdueCount  = count(array_filter($orders, fn ($o) => $o['segment'] === 'overdue'));

            return [
                'product_id'     => $product->id,
                'room_name'      => $product->name,
                'branch'         => $branchName,
                'branch_id'      => $branchId,
                'styles'         => (int) $product->styles,
                'orders'         => $orders,
                'count'          => count($orders),
                'active_count'   => $activeCount,
                'today_count'    => $todayCount,
                'upcoming_count' => $upcomingCount,
                'overdue_count'  => $overdueCount,
                'has_new'        => $hasNew,
                'latest_time'    => $latestTime,
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
