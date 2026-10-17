<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Promotion;

/**
 * GHI cấu hình giá theo NGÀY của phòng theo ngày (styles=2) — nguồn DUY NHẤT cho cả trang admin web
 * (SettingBook → lịch "Ngày đặc biệt & Khuyến mãi") lẫn API admin cho app (DailyRoomPricingController).
 *
 * Mô hình dữ liệu (giữ nguyên, mọi luồng đọc giá đã dùng sẵn — BuildsRoomBooking, BookingController,
 * GuestBookingController, DailyRoomController, Admin\RoomController::dates, OrderForm, ProductDetail):
 *  - 1 ngày = 1 row room_time_slots trỏ tới time_slots(type=date, label=Y-m-d).
 *  - price != null  → giá ghi đè của đêm bắt đầu ngày đó; null → giá gốc phòng (products.price).
 *  - checkin/checkout null → dùng default_checkin/default_checkout của phòng.
 *  - promotion_room_time_slot → KM giảm (percentage/fixed) + phụ thu (increase_*), tính bằng
 *    PromotionCalculator::calculateForDate() (phụ thu cộng trước, KM giảm tính trên giá sau phụ thu).
 *  - coupon_room_time_slot → mã giảm giá chỉ dùng được cho các đêm đó.
 * Row không còn giá ghi đè, giờ riêng, KM lẫn mã → bị xoá (ngày trở về giá gốc).
 *
 * Lọc theo thứ ($weekdays, ISO 1=Thứ 2 … 7=Chủ nhật) chỉ áp ở lúc GHI: Promotion không có cột ngày
 * trong tuần, nên "T2–T5 giảm 40%" = gắn KM 40% vào đúng các ngày T2–T5 trong khoảng.
 */
class DailyRoomPricingService
{
    /** Khoảng tối đa 1 thao tác hàng loạt (đủ cho 1 năm + vắt sang Tết năm sau). */
    public const MAX_DAYS = 400;

    public const SURCHARGE_TYPES = ['increase_percentage', 'increase_fixed'];
    public const DISCOUNT_TYPES  = ['percentage', 'fixed'];

    public function __construct(private SlotRealtimeService $realtime)
    {
    }

    /**
     * Các ngày Y-m-d từ $from đến $to (BAO GỒM cả 2 đầu), chỉ giữ ngày có thứ nằm trong $weekdays
     * (rỗng = mọi ngày).
     *
     * @param  array<int|string>  $weekdays
     * @return list<string>
     */
    public function dates(string $from, string $to, array $weekdays = []): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->startOfDay();

        if ($start->gt($end)) {
            throw new InvalidArgumentException('Ngày bắt đầu phải trước hoặc bằng ngày kết thúc.');
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('Khoảng ngày tối đa ' . self::MAX_DAYS . ' ngày.');
        }

        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));

        $dates = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($weekdays === [] || in_array($day->dayOfWeekIso, $weekdays, true)) {
                $dates[] = $day->format('Y-m-d');
            }
        }

        return $dates;
    }

    /** Ghi đè giá (+ giờ nhận/trả) cho các ngày — giữ nguyên KM/mã đang gắn. */
    public function setPrice(Product $room, array $dates, int $price, ?string $checkin = null, ?string $checkout = null): int
    {
        $checkin  = $this->normalizeTime($checkin, $room->default_checkin ?? '14:00');
        $checkout = $this->normalizeTime($checkout, $room->default_checkout ?? '12:00');

        return $this->write($room, $dates, 'Ghi đè giá ' . number_format($price, 0, ',', '.') . 'đ', function (Collection $slots) use ($price, $checkin, $checkout) {
            foreach ($slots as $slot) {
                $slot->update(['price' => $price, 'checkin' => $checkin, 'checkout' => $checkout, 'status' => 'available']);
            }
        });
    }

    /** Gỡ giá ghi đè + giờ riêng — giữ KM/mã (ngày tính theo giá gốc). */
    public function clearPrice(Product $room, array $dates): int
    {
        return $this->write($room, $dates, 'Gỡ giá ghi đè', function (Collection $slots) {
            foreach ($slots as $slot) {
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
            }
        }, create: false);
    }

    /**
     * Gắn KM/phụ thu. Mặc định gắn THÊM (giữ KM khác đang gắn); $replace = thay toàn bộ KM + phụ thu
     * của các ngày đó bằng đúng $promotionIds (vd ngày Tết: bỏ KM cuối tuần, chỉ còn +10%).
     */
    public function attachPromotions(Product $room, array $dates, array $promotionIds, bool $replace = false): int
    {
        $promotionIds = $this->ids($promotionIds);
        if ($promotionIds === []) {
            return 0;
        }

        $label = ($replace ? 'Thay KM/phụ thu bằng #' : 'Gắn KM/phụ thu #') . implode(', #', $promotionIds);

        return $this->write($room, $dates, $label, function (Collection $slots) use ($promotionIds, $replace) {
            foreach ($slots as $slot) {
                $replace ? $slot->promotions()->sync($promotionIds) : $slot->promotions()->syncWithoutDetaching($promotionIds);
            }
        });
    }

    /**
     * Gỡ KM/phụ thu. $promotionIds rỗng = gỡ TẤT CẢ thuộc nhóm $kind ('discount' | 'surcharge');
     * $kind null + ids rỗng = gỡ mọi KM lẫn phụ thu.
     */
    public function detachPromotions(Product $room, array $dates, array $promotionIds = [], ?string $kind = null): int
    {
        $promotionIds = $this->ids($promotionIds);

        return $this->write($room, $dates, 'Gỡ ' . ($kind === 'surcharge' ? 'phụ thu' : 'KM'), function (Collection $slots) use ($promotionIds, $kind) {
            foreach ($slots as $slot) {
                $ids = $promotionIds;

                if ($ids === []) {
                    $ids = $slot->promotions
                        ->filter(fn (Promotion $p) => match ($kind) {
                            'surcharge' => in_array($p->type, self::SURCHARGE_TYPES, true),
                            'discount'  => ! in_array($p->type, self::SURCHARGE_TYPES, true),
                            default     => true,
                        })
                        ->pluck('id')
                        ->all();
                }

                if ($ids !== []) {
                    $slot->promotions()->detach($ids);
                }
            }
        }, create: false);
    }

    /** Đánh dấu KM do thao tác "nhập giá trị" tạo ra (description) — để lần sau dùng lại, không đẻ trùng. */
    public const QUICK_PROMO_MARK = 'auto:daily-pricing';

    /**
     * KM "nhập nhanh" theo giá trị (vd giảm 40%, giảm 50.000đ, tăng 10%) cho phòng theo ngày: dùng lại
     * KM tự tạo cùng đối tác + loại + giá trị nếu đã có (nới hạn start_at/end_at cho phủ $from..$to),
     * chưa có thì tạo mới. Hạn KM chỉ cần phủ các ngày được gắn — KM chỉ có tác dụng ở đúng ngày gắn.
     *
     * @throws InvalidArgumentException
     */
    public function quickPromotion(Product $room, string $type, int $value, string $from, string $to, ?\App\Models\User $user = null): Promotion
    {
        if (! in_array($type, [...self::DISCOUNT_TYPES, ...self::SURCHARGE_TYPES], true)) {
            throw new InvalidArgumentException('Loại khuyến mãi không hợp lệ.');
        }
        if ($value <= 0 || (str_ends_with($type, 'percentage') && $value > 100) || $value > 100_000_000) {
            throw new InvalidArgumentException('Giá trị khuyến mãi không hợp lệ (1–100% hoặc số tiền > 0).');
        }

        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->endOfDay();

        $promo = Promotion::withoutGlobalScopes()
            ->where('description', self::QUICK_PROMO_MARK)
            ->where('partner_id', $room->partner_id)
            ->where('type', $type)
            ->where('value', $value)
            ->where('is_active', true)
            ->first();

        $label = $this->promotionValueLabel($type, (float) $value);
        $name  = (str_starts_with($type, 'increase') ? 'Phụ thu ' : 'Giảm ') . ltrim($label, '+−') . ' (theo ngày)';

        if ($promo) {
            $promo->update([
                'start_at' => $promo->start_at && $promo->start_at->lt($start) ? $promo->start_at : $start,
                'end_at'   => $promo->end_at && $promo->end_at->gt($end) ? $promo->end_at : $end,
            ]);
        } else {
            $promo = Promotion::create([
                'partner_id'   => $room->partner_id,
                'name'         => $name,
                'description'  => self::QUICK_PROMO_MARK,
                'type'         => $type,
                'value'        => $value,
                'lable_client' => mb_substr($label, 0, 100),
                'start_at'     => $start,
                'end_at'       => $end,
                'is_active'    => true,
                'created_by'   => $user?->getKey(),
            ]);
        }

        $this->optionsCache = [];

        return $promo->fresh();
    }

    /** Dạng 1 KM như 1 phần tử của options() — để client thêm vào danh sách chọn ngay. */
    public function promotionOption(Promotion $p): array
    {
        return [
            'id'       => (int) $p->id,
            'name'     => $p->name,
            'type'     => $p->type,
            'value'    => (float) $p->value,
            'start_at' => $p->start_at?->format('Y-m-d H:i:s'),
            'end_at'   => $p->end_at?->format('Y-m-d H:i:s'),
            'label'    => $p->name . ' (' . $this->promotionValueLabel($p->type, (float) $p->value) . ' · '
                . $p->start_at?->format('d/m/Y') . ' → ' . $p->end_at?->format('d/m/Y') . ')',
        ];
    }

    /** Gắn mã. $replace = thay toàn bộ mã của các ngày đó bằng đúng $couponIds. */
    public function attachCoupons(Product $room, array $dates, array $couponIds, bool $replace = false): int
    {
        $couponIds = $this->ids($couponIds);
        if ($couponIds === []) {
            return 0;
        }

        $label = ($replace ? 'Thay mã giảm giá bằng #' : 'Gắn mã giảm giá #') . implode(', #', $couponIds);

        return $this->write($room, $dates, $label, function (Collection $slots) use ($couponIds, $replace) {
            foreach ($slots as $slot) {
                $replace ? $slot->coupons()->sync($couponIds) : $slot->coupons()->syncWithoutDetaching($couponIds);
            }
        });
    }

    /** $couponIds rỗng = gỡ tất cả mã. */
    public function detachCoupons(Product $room, array $dates, array $couponIds = []): int
    {
        $couponIds = $this->ids($couponIds);

        return $this->write($room, $dates, 'Gỡ mã giảm giá', function (Collection $slots) use ($couponIds) {
            foreach ($slots as $slot) {
                $couponIds === [] ? $slot->coupons()->detach() : $slot->coupons()->detach($couponIds);
            }
        }, create: false);
    }

    /**
     * Lưu TRỌN cấu hình 1 ngày (thay thế toàn bộ): giá ghi đè (null = giá gốc), giờ, KM + phụ thu, mã.
     * Không còn gì → ngày trở về giá gốc.
     */
    public function saveDate(
        Product $room,
        string $date,
        ?int $price,
        ?string $checkin,
        ?string $checkout,
        array $promotionIds = [],
        array $couponIds = []
    ): void {
        $checkin      = $this->normalizeTime($checkin, $room->default_checkin ?? '14:00');
        $checkout     = $this->normalizeTime($checkout, $room->default_checkout ?? '12:00');
        $promotionIds = $this->ids($promotionIds);
        $couponIds    = $this->ids($couponIds);

        $this->write($room, [$date], 'Sửa ngày', function (Collection $slots) use ($price, $checkin, $checkout, $promotionIds, $couponIds) {
            foreach ($slots as $slot) {
                $slot->update(['price' => $price, 'checkin' => $checkin, 'checkout' => $checkout, 'status' => 'available']);
                $slot->promotions()->sync($promotionIds);
                $slot->coupons()->sync($couponIds);
            }
        });
    }

    /** Xoá MỌI cấu hình của các ngày (giá, giờ, KM, mã) → về giá gốc. */
    public function clearDates(Product $room, array $dates): int
    {
        return $this->write($room, $dates, 'Xoá cấu hình ngày', function (Collection $slots) {
            foreach ($slots as $slot) {
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
                $slot->promotions()->detach();
                $slot->coupons()->detach();
            }
        }, create: false);
    }

    /** Xoá toàn bộ ngày đặc biệt của phòng. */
    public function clearAll(Product $room): int
    {
        $dates = RoomTimeSlot::where('room_id', $room->id)
            ->whereHas('timeSlot', fn ($q) => $q->where('type', 'date'))
            ->with('timeSlot')
            ->get()
            ->pluck('timeSlot.label')
            ->filter()
            ->values()
            ->all();

        return $dates === [] ? 0 : $this->clearDates($room, $dates);
    }

    /**
     * Lịch giá đã TÍNH SẴN (đúng công thức các luồng đặt phòng dùng) + cấu hình thô từng ngày —
     * cho API app admin.
     *
     * @return list<array<string, mixed>>
     */
    public function calendar(Product $room, string $from, string $to): array
    {
        $dates = $this->dates($from, $to);
        $slots = $this->slotsByDate($room, $dates);

        $calculator = app(PromotionCalculator::class);
        $resolver   = app(\App\Services\PriceBoards\StayPriceResolver::class);
        $basePrice  = (float) $room->price;

        return array_map(function (string $date) use ($slots, $calculator, $resolver, $room, $basePrice) {
            $rts   = $slots->get($date);
            $price = $rts?->price !== null
                ? (float) $rts->price
                : $resolver->nightPrice($room, $date, $basePrice);

            $result = $calculator->calculateForDate($rts, $price, $date);
            $promos = $rts?->promotions ?? collect();

            return [
                'date'           => $date,
                'weekday'        => Carbon::parse($date)->dayOfWeekIso,
                'override_price' => $rts?->price !== null ? (int) $rts->price : null,
                'price'          => (int) $price,
                'final_price'    => (int) $result['final_price'],
                'checkin'        => substr((string) ($rts?->checkin ?? ($room->default_checkin ?? '14:00')), 0, 5),
                'checkout'       => substr((string) ($rts?->checkout ?? ($room->default_checkout ?? '12:00')), 0, 5),
                'has_custom_time'=> $rts !== null && ($rts->checkin !== null || $rts->checkout !== null),
                'promotion_ids'  => $promos->reject(fn ($p) => in_array($p->type, self::SURCHARGE_TYPES, true))->pluck('id')->values()->all(),
                'surcharge_ids'  => $promos->filter(fn ($p) => in_array($p->type, self::SURCHARGE_TYPES, true))->pluck('id')->values()->all(),
                'coupon_ids'     => $rts?->coupons->pluck('id')->values()->all() ?? [],
                'applied'        => $result['applied'],
            ];
        }, $dates);
    }

    /**
     * KM giảm / phụ thu / mã giảm giá $user được gắn vào ngày. Chỉ lấy loại CÓ TÁC DỤNG: KM phải đang
     * bật, có đủ start_at + end_at (PromotionCalculator bỏ qua KM thiếu 1 trong 2) và chưa hết hạn;
     * mã bỏ bản sao cá nhân (customer_id != null — voucher hạng cấp riêng từng khách, hàng nghìn bản).
     *
     * @return array{promotions: list<array>, surcharges: list<array>, coupons: list<array>}
     */
    public function options(?\App\Models\User $user): array
    {
        return $this->optionsCache[(string) $user?->getKey()] ??= $this->loadOptions($user);
    }

    /** @var array<string, array> */
    private array $optionsCache = [];

    private function loadOptions(?\App\Models\User $user): array
    {
        $partnerId = $user && ! $user->seesAllPartners() ? $user->partner_id : null;
        $today     = now()->startOfDay();

        $promotions = Promotion::query()
            ->where('is_active', true)
            ->whereNotNull('start_at')
            ->where('end_at', '>=', $today)
            ->when($partnerId, fn ($q) => $q->where(fn ($q2) => $q2->where('partner_id', $partnerId)->orWhereNull('partner_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'value', 'start_at', 'end_at']);

        $map = fn (Promotion $p) => [
            'id'       => (int) $p->id,
            'name'     => $p->name,
            'type'     => $p->type,
            'value'    => (float) $p->value,
            'start_at' => $p->start_at?->format('Y-m-d H:i:s'),
            'end_at'   => $p->end_at?->format('Y-m-d H:i:s'),
            'label'    => $p->name . ' (' . $this->promotionValueLabel($p->type, (float) $p->value) . ' · '
                . $p->start_at?->format('d/m/Y') . ' → ' . $p->end_at?->format('d/m/Y') . ')',
        ];

        $coupons = \Modules\Promotion\App\Models\Coupon::query()
            ->where('is_active', true)
            ->whereNull('customer_id')
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $today))
            ->when($partnerId, fn ($q) => $q->where(fn ($q2) => $q2->where('partner_id', $partnerId)->orWhereNull('partner_id')))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'value', 'end_at']);

        return [
            'promotions' => $promotions->filter(fn ($p) => in_array($p->type, self::DISCOUNT_TYPES, true))->map($map)->values()->all(),
            'surcharges' => $promotions->filter(fn ($p) => in_array($p->type, self::SURCHARGE_TYPES, true))->map($map)->values()->all(),
            'coupons'    => $coupons->map(fn ($c) => [
                'id'     => (int) $c->id,
                'code'   => $c->code,
                'name'   => $c->name,
                'end_at' => $c->end_at ? Carbon::parse($c->end_at)->format('Y-m-d H:i:s') : null,
                'label'  => $c->name . ($c->code ? ' — ' . $c->code : ''),
            ])->values()->all(),
        ];
    }

    private function promotionValueLabel(string $type, float $value): string
    {
        $num = rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');

        return match ($type) {
            'percentage'          => '−' . $num . '%',
            'fixed'               => '−' . number_format($value, 0, ',', '.') . 'đ',
            'increase_percentage' => '+' . $num . '%',
            'increase_fixed'      => '+' . number_format($value, 0, ',', '.') . 'đ',
            default               => $num,
        };
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Chạy $mutate trên các row của $dates (tạo row thiếu nếu $create), dọn row rỗng, ghi 1 dòng
     * audit tóm tắt và báo realtime. Trả về số ngày bị tác động.
     */
    private function write(Product $room, array $dates, string $label, \Closure $mutate, bool $create = true): int
    {
        $dates = array_values(array_unique(array_map(fn ($d) => Carbon::parse($d)->format('Y-m-d'), $dates)));
        if ($dates === []) {
            return 0;
        }

        $count = RoomTimeSlot::withoutAuditLog(function () use ($room, $dates, $mutate, $create) {
            return DB::transaction(function () use ($room, $dates, $mutate, $create) {
                $slots = $create ? $this->ensureSlots($room, $dates) : $this->slotsByDate($room, $dates);

                $mutate($slots);

                $this->pruneEmpty($slots);

                return $slots->count();
            });
        });

        if ($count > 0) {
            AuditLogger::log(
                action: 'update',
                module: 'RoomTimeSlot',
                record: $room,
                new: [
                    'thao_tac' => $label,
                    'so_ngay'  => $count,
                    'tu_ngay'  => $dates[0],
                    'den_ngay' => end($dates),
                ],
                label: 'Phòng ' . ($room->name ?? '#' . $room->id) . ' — Giá theo ngày: ' . $label,
            );

            $this->realtime->broadcastBlockedRange((string) $room->id, $dates, [], 'available');
        }

        return $count;
    }

    /** Row hiện có của các ngày, key = Y-m-d. */
    private function slotsByDate(Product $room, array $dates): Collection
    {
        return RoomTimeSlot::where('room_id', $room->id)
            ->whereHas('timeSlot', fn ($q) => $q->where('type', 'date')->whereIn('label', $dates))
            ->with(['timeSlot', 'promotions', 'coupons'])
            ->get()
            ->keyBy(fn (RoomTimeSlot $s) => $s->timeSlot->label);
    }

    /** Như slotsByDate() nhưng tạo row (và time_slots type=date) còn thiếu. */
    private function ensureSlots(Product $room, array $dates): Collection
    {
        $slots   = $this->slotsByDate($room, $dates);
        $missing = array_values(array_diff($dates, $slots->keys()->all()));

        if ($missing === []) {
            return $slots;
        }

        $timeSlots = TimeSlot::where('type', 'date')->whereIn('label', $missing)->get()->keyBy('label');

        foreach ($missing as $date) {
            $timeSlot = $timeSlots->get($date) ?? TimeSlot::create([
                'label' => $date, 'type' => 'date', 'start_time' => null, 'end_time' => null,
            ]);

            $slot = RoomTimeSlot::create([
                'room_id' => $room->id, 'timeslot_id' => $timeSlot->id, 'price' => null, 'status' => 'available',
            ]);
            $slot->setRelation('timeSlot', $timeSlot);
            $slot->setRelation('promotions', collect());
            $slot->setRelation('coupons', collect());

            $slots->put($date, $slot);
        }

        return $slots;
    }

    private function pruneEmpty(Collection $slots): void
    {
        foreach ($slots as $slot) {
            $slot->load(['promotions', 'coupons']);

            if ($slot->price === null && $slot->checkin === null && $slot->checkout === null
                && $slot->promotions->isEmpty() && $slot->coupons->isEmpty()) {
                $slot->delete();
            }
        }
    }

    /** Giờ trùng giờ mặc định của phòng → lưu null (đổi giờ mặc định phòng sau này vẫn ăn theo). */
    private function normalizeTime(?string $time, string $default): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $time = substr($time, 0, 5);

        return $time === substr($default, 0, 5) ? null : $time;
    }

    /** @return list<int> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
