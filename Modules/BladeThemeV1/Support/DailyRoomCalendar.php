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

                // Bảng giá kiểu giảm % / giảm tiền đang áp đêm này → hiện như khuyến mãi: giá gốc + % giảm THỰC TẾ (để lịch tính
                // ra đúng giá đã làm tròn của server) + nhãn. Ngày đã có khuyến mãi thật thì giữ khuyến mãi đó.
                $board = isset($promoDiscounts[$iso]) ? null : $resolver->boardDiscountForNight($product, $iso, $base);

                if ($board && $board['price'] === $price) {
                    $stayPrices[$iso]     = $board['base'];
                    $promoDiscounts[$iso] = ($board['base'] - $board['price']) / $board['base'] * 100;
                    $promoLabels[$iso]    = $board['label'];
                } elseif ($price !== (int) $base) {
                    $stayPrices[$iso] = $price;
                }
            }
        }

        return [
            'pricePerNight'      => (int) ($product->price ?? 0),
            'bookedRanges'       => $bookedRanges ?? (self::bookedRangesFor([$product->id])[$product->id] ?? []),
            // Chỉ khoảng khoá TRỌN phòng hiện 🔒; khoá một phần số phòng của biến thể đã trừ vào bookedRanges bên trên.
            'adminBlockedRanges' => $product->fullBlockedRanges(),
            'datePrices'         => $datePrices,
            'stayPrices'         => $stayPrices,
            'dayPrices'          => $dayPrices,
            'promoDiscounts'     => $promoDiscounts,
            'promoLabels'        => $promoLabels,
            'surchargeData'      => $surchargeData,
        ];
    }

    /** order_items đang giữ phòng: đơn đã thanh toán/cọc/xác nhận, hoặc đơn chờ thanh toán chưa hết hạn. */
    private static function holdingItemsQuery(array $productIds)
    {
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
            });
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

        $variants  = Product::whereIn('id', $productIds)->where('is_variant', true)
            ->get(['id', 'is_variant', 'variant_quantity', 'room_config', 'default_checkout'])
            ->keyBy(fn ($p) => (string) $p->id);
        $inventory = app(\App\Services\RoomInventory::class);
        $items     = self::holdingItemsQuery($productIds)
            ->get(['product_id', 'checkin_date', 'checkout_date', 'quantity'])
            ->groupBy(fn ($it) => (string) $it->product_id);

        $result = [];
        foreach ($items->keys()->merge($variants->keys())->unique() as $productId) {
            $productItems = $items->get($productId, collect());

            if (isset($variants[$productId])) {
                // Biến thể phòng: chỉ khoá những khoảng đã hết sạch phòng (đơn + phần phòng admin khoá), còn phòng thì
                // khách vẫn chọn được. Khoảng đầy kết thúc lúc trả phòng (vd 12:00 ngày 06) → đêm cuối bị khoá là đêm
                // hôm trước (05); không gạch ngày 06 (picker coi start..end là các ngày khoá, tính cả 2 đầu).
                $variant = $variants[$productId];
                $ranges  = collect($inventory->fullIntervals($productItems->concat($inventory->blockItems($variant)), $variant->roomCapacity()))
                    ->map(fn ($r) => [$r[0], $r[1]->copy()->subDay()->max($r[0])])->all();
            } else {
                $ranges = $productItems->map(fn ($it) => [$it->checkin_date, $it->checkout_date])->all();
            }

            if ($ranges) {
                $result[$productId] = collect($ranges)->map(fn ($range) => [
                    'start' => Carbon::parse($range[0])->format('Y-m-d'),
                    'end'   => Carbon::parse($range[1])->format('Y-m-d'),
                ])->values()->all();
            }
        }

        return $result;
    }
}
