<?php

declare(strict_types=1);

namespace Modules\BladeThemeV1\Support;

use Carbon\Carbon;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;

/**
 * Dữ liệu lịch đặt phòng THEO NGÀY (styles=2) cho widget lịch — dùng chung cho trang chi tiết phòng
 * (components/product-detail/daterange-picker.blade.php) và Lịch đặt phòng trực tuyến của chi nhánh
 * (livewire/book/_daily-cards.blade.php), để 2 nơi luôn tính giá/ngày kín giống hệt nhau.
 */
class DailyRoomCalendar
{
    /** Trạng thái đơn còn giữ phòng (pending chỉ khi chưa hết hạn giữ chỗ). */
    private const HOLDING_STATUSES = ['paid', 'deposit', 'shipping', 'completed', 'confirmed'];

    /**
     * @param  array<int, array{start: string, end: string}>|null  $bookedRanges  truyền sẵn khi đã gom 1 query cho nhiều phòng (bookedRangesFor())
     * @return array{pricePerNight: int, bookedRanges: array, adminBlockedRanges: array, datePrices: array, stayPrices: array, dayPrices: array, promoDiscounts: array, promoLabels: array, surchargeData: array}
     */
    public static function data(Product $product, ?array $bookedRanges = null): array
    {
        $product->loadMissing(['roomTimeSlots.timeSlot', 'roomTimeSlots.promotions']);

        $dayPriceMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];
        $dayPrices   = [];
        foreach ($product->day_prices ?? [] as $k => $v) {
            if (isset($dayPriceMap[$k]) && (int) $v > 0) {
                $dayPrices[$dayPriceMap[$k]] = (int) $v;
            }
        }

        $datePrices     = [];
        $promoLabels    = [];
        $promoDiscounts = [];
        $surchargeData  = [];
        $now            = now();

        foreach ($product->roomTimeSlots ?? [] as $rts) {
            if (! $rts->timeSlot || ($rts->timeSlot->type ?? '') !== 'date' || empty($rts->timeSlot->label)) {
                continue;
            }

            $dk = $rts->timeSlot->label;
            if ($rts->price !== null) {
                $datePrices[$dk] = (int) $rts->price;
            }

            $maxDisc    = 0;
            $bestLbl    = '';
            $surcharges = [];
            foreach ($rts->promotions as $promo) {
                if (! $promo->is_active) {
                    continue;
                }
                $inPeriod = (! $promo->start_at || $promo->start_at <= $now) && (! $promo->end_at || $promo->end_at >= $now);
                if (! $inPeriod) {
                    continue;
                }
                if ($promo->type === 'percentage') {
                    if ((float) $promo->value > $maxDisc) {
                        $maxDisc = (float) $promo->value;
                        $bestLbl = $promo->lable_client ?: $promo->name;
                    }
                } elseif (in_array($promo->type, ['increase_percentage', 'increase_fixed'], true)) {
                    $surcharges[] = ['type' => $promo->type, 'value' => (float) $promo->value];
                }
            }
            if ($maxDisc > 0) {
                $promoDiscounts[$dk] = $maxDisc;
                $promoLabels[$dk]    = $bestLbl;
            }
            if (! empty($surcharges)) {
                $surchargeData[$dk] = $surcharges;
            }
        }

        // Giá theo thứ của bảng giá (giai đoạn 2): chỉ những ngày KHÔNG có giá ngày đặc biệt và khác giá gốc.
        $stayPrices = [];

        if (\Modules\Product\App\Models\PriceBoard::phase2Enabled()) {
            $resolver = app(\App\Services\PriceBoards\StayPriceResolver::class);
            $base     = (float) ($product->price ?? 0);

            for ($day = now()->startOfDay(), $i = 0; $i < 400; $i++, $day->addDay()) {
                $iso = $day->format('Y-m-d');

                if (isset($datePrices[$iso])) {
                    continue;
                }

                $price = (int) round($resolver->nightPrice($product, $iso, $base));

                if ($price !== (int) $base) {
                    $stayPrices[$iso] = $price;
                }
            }
        }

        return [
            'pricePerNight'      => (int) ($product->price ?? 0),
            'bookedRanges'       => $bookedRanges ?? (self::bookedRangesFor([$product->id])[$product->id] ?? []),
            'adminBlockedRanges' => array_values(array_filter(
                $product->room_config['blocked_ranges'] ?? [],
                fn ($r) => ! empty($r['start']) && ! empty($r['end']),
            )),
            'datePrices'         => $datePrices,
            'stayPrices'         => $stayPrices,
            'dayPrices'          => $dayPrices,
            'promoDiscounts'     => $promoDiscounts,
            'promoLabels'        => $promoLabels,
            'surchargeData'      => $surchargeData,
        ];
    }

    /**
     * Khoảng ngày đã có đơn giữ phòng, gom 1 query cho nhiều phòng.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<string, array<int, array{start: string, end: string}>>  product_id => ranges
     */
    public static function bookedRangesFor(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        return OrderItem::whereIn('product_id', $productIds)
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->whereHas('order', function ($q) {
                $q->where(function ($inner) {
                    $inner->whereIn('status', self::HOLDING_STATUSES)
                        ->orWhere(function ($pq) {
                            $pq->where('status', 'pending')->where(function ($eq) {
                                $eq->whereNull('expired_at')->orWhere('expired_at', '>', now());
                            });
                        });
                });
            })
            ->get(['product_id', 'checkin_date', 'checkout_date'])
            ->groupBy(fn ($it) => (string) $it->product_id)
            ->map(fn ($items) => $items->map(fn ($it) => [
                'start' => Carbon::parse($it->checkin_date)->format('Y-m-d'),
                'end'   => Carbon::parse($it->checkout_date)->format('Y-m-d'),
            ])->values()->all())
            ->all();
    }
}
