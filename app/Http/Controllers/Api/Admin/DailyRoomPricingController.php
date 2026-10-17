<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DailyRoomPricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Product\App\Models\Product;

/**
 * Lịch giá theo NGÀY của phòng theo ngày (styles=2) cho app admin — cùng dữ liệu & cùng service ghi
 * (DailyRoomPricingService) với lịch "Ngày đặc biệt & Khuyến mãi" ở /homestay/admin/books.
 *
 *  GET    /api/admin/rooms/{id}/date-pricing?month=YYYY-MM  (hoặc ?from=Y-m-d&to=Y-m-d, tối đa 400 ngày)
 *         → base_price, giờ mặc định, options (KM / phụ thu / mã gắn được) và từng ngày: override_price,
 *           price (giá trước KM), final_price (giá cuối / đêm, đúng công thức đặt phòng, chưa tính mã),
 *           checkin/checkout, promotion_ids / surcharge_ids / coupon_ids, applied (KM thực sự áp).
 *  POST   /api/admin/rooms/{id}/date-pricing/bulk
 *         body: action, date_from, date_to, weekdays[] (1=T2 … 7=CN, bỏ trống = mọi ngày), +
 *           set_price          : price (bắt buộc), checkin, checkout (H:i)
 *           clear_price        : —  (gỡ giá ghi đè + giờ riêng, giữ KM/mã)
 *           attach_promotions  : promotion_ids[] và/hoặc custom_promotion {type: percentage|fixed|increase_percentage|increase_fixed,
 *                                value: int} (KM theo giá trị nhập tay, tạo / dùng lại) — GẮN THÊM; replace=true = thay toàn bộ
 *           detach_promotions  : promotion_ids[] (rỗng = gỡ hết theo kind), kind: discount | surcharge
 *           attach_coupons     : coupon_ids[] (replace=true = thay toàn bộ mã)
 *           detach_coupons     : coupon_ids[] (rỗng = gỡ hết mã)
 *           clear              : —  (xoá MỌI thiết lập → về giá gốc, vd ngày lễ)
 *  PUT    /api/admin/rooms/{id}/date-pricing/{date}
 *         body: price (null = giá gốc), checkin, checkout, promotion_ids[], coupon_ids[] — THAY THẾ toàn
 *         bộ thiết lập của ngày đó (gửi rỗng hết = về giá gốc).
 *  DELETE /api/admin/rooms/{id}/date-pricing/{date} → về giá gốc.
 *
 * Ví dụ "T2–T5 −40%, T6–CN −20%, lễ giá gốc, Tết +10%" cho cả năm:
 *   1. bulk attach_promotions {KM 40%} weekdays=[1,2,3,4]   2. bulk attach_promotions {KM 20%} weekdays=[5,6,7]
 *   3. bulk clear cho từng khoảng lễ                         4. bulk clear rồi attach_promotions {phụ thu 10%} cho Tết
 */
class DailyRoomPricingController extends Controller
{
    private const ACTIONS = [
        'set_price', 'clear_price', 'attach_promotions', 'detach_promotions',
        'attach_coupons', 'detach_coupons', 'clear',
    ];

    public function __construct(private DailyRoomPricingService $pricing)
    {
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng theo ngày.'], 404);
        }

        if ($request->has('from') || $request->has('to')) {
            $request->validate([
                'from' => 'required|date_format:Y-m-d',
                'to'   => 'required|date_format:Y-m-d|after_or_equal:from',
            ]);
            $from = $request->query('from');
            $to   = $request->query('to');
        } else {
            $request->validate(['month' => 'nullable|date_format:Y-m']);
            $month = Carbon::createFromFormat('Y-m', $request->query('month', now()->format('Y-m')))->startOfMonth();
            $from  = $month->format('Y-m-d');
            $to    = $month->copy()->endOfMonth()->format('Y-m-d');
        }

        try {
            $dates = $this->pricing->calendar($room, $from, $to);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'room'             => ['id' => $room->id, 'name' => $room->name, 'slug' => $room->slug],
            'from'             => $from,
            'to'               => $to,
            'base_price'       => (int) $room->price,
            'default_checkin'  => $room->default_checkin ?? '14:00',
            'default_checkout' => $room->default_checkout ?? '12:00',
            'options'          => $this->pricing->options($request->user()),
            'dates'            => $dates,
        ]);
    }

    public function bulk(Request $request, string $id): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng theo ngày.'], 404);
        }

        $data = $request->validate([
            'action'          => ['required', Rule::in(self::ACTIONS)],
            'date_from'       => 'required|date_format:Y-m-d',
            'date_to'         => 'required|date_format:Y-m-d|after_or_equal:date_from',
            'weekdays'        => 'nullable|array',
            'weekdays.*'      => 'integer|between:1,7',
            'price'           => 'required_if:action,set_price|nullable|integer|min:0',
            'checkin'         => 'nullable|date_format:H:i',
            'checkout'        => 'nullable|date_format:H:i',
            'promotion_ids'   => ['nullable', 'array', Rule::requiredIf(fn () => $request->input('action') === 'attach_promotions' && ! $request->filled('custom_promotion'))],
            'promotion_ids.*' => 'integer',
            // KM theo giá trị nhập tay — tạo / dùng lại 1 KM "(theo ngày)" rồi gắn như promotion_ids.
            'custom_promotion'       => 'nullable|array',
            'custom_promotion.type'  => ['required_with:custom_promotion', Rule::in(['percentage', 'fixed', 'increase_percentage', 'increase_fixed'])],
            'custom_promotion.value' => 'required_with:custom_promotion|integer|min:1',
            'coupon_ids'      => 'required_if:action,attach_coupons|array',
            'coupon_ids.*'    => 'integer',
            'kind'            => ['nullable', Rule::in(['discount', 'surcharge'])],
            'replace'         => 'nullable|boolean',
        ]);

        try {
            $dates = $this->pricing->dates($data['date_from'], $data['date_to'], $data['weekdays'] ?? []);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($dates === []) {
            return response()->json(['message' => 'Không có ngày nào khớp thứ đã chọn trong khoảng này.'], 422);
        }

        $user = $request->user();

        if ($data['action'] === 'attach_promotions' && $error = $this->foreignIdsError($user, $data['promotion_ids'] ?? [], 'promotion')) {
            return response()->json(['message' => $error], 422);
        }

        $createdPromotion = null;
        if ($data['action'] === 'attach_promotions' && ! empty($data['custom_promotion'])) {
            try {
                $createdPromotion = $this->pricing->quickPromotion($room, $data['custom_promotion']['type'], (int) $data['custom_promotion']['value'], $data['date_from'], $data['date_to'], $user);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $data['promotion_ids'] = array_merge($data['promotion_ids'] ?? [], [$createdPromotion->id]);
        }
        if ($data['action'] === 'attach_coupons' && $error = $this->foreignIdsError($user, $data['coupon_ids'] ?? [], 'coupon')) {
            return response()->json(['message' => $error], 422);
        }

        $count = match ($data['action']) {
            'set_price'         => $this->pricing->setPrice($room, $dates, (int) $data['price'], $data['checkin'] ?? null, $data['checkout'] ?? null),
            'clear_price'       => $this->pricing->clearPrice($room, $dates),
            'attach_promotions' => $this->pricing->attachPromotions($room, $dates, $data['promotion_ids'], (bool) ($data['replace'] ?? false)),
            'detach_promotions' => $this->pricing->detachPromotions($room, $dates, $data['promotion_ids'] ?? [], $data['kind'] ?? null),
            'attach_coupons'    => $this->pricing->attachCoupons($room, $dates, $data['coupon_ids'], (bool) ($data['replace'] ?? false)),
            'detach_coupons'    => $this->pricing->detachCoupons($room, $dates, $data['coupon_ids'] ?? []),
            'clear'             => $this->pricing->clearDates($room, $dates),
        };

        return response()->json([
            'message'        => "Đã cập nhật {$count} ngày.",
            'affected_days'  => $count,
            'matched_dates'  => count($dates),
            // KM vừa tạo / dùng lại từ custom_promotion (null nếu không gửi) — app thêm vào options.
            'promotion'      => $createdPromotion ? $this->pricing->promotionOption($createdPromotion) : null,
            'dates'          => $this->pricing->calendar($room, $dates[0], end($dates)),
        ]);
    }

    public function update(Request $request, string $id, string $date): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng theo ngày.'], 404);
        }

        $request->merge(['date' => $date]);
        $data = $request->validate([
            'date'            => 'required|date_format:Y-m-d',
            'price'           => 'nullable|integer|min:0',
            'checkin'         => 'nullable|date_format:H:i',
            'checkout'        => 'nullable|date_format:H:i',
            'promotion_ids'   => 'nullable|array',
            'promotion_ids.*' => 'integer',
            'coupon_ids'      => 'nullable|array',
            'coupon_ids.*'    => 'integer',
        ]);

        $user = $request->user();
        if ($error = $this->foreignIdsError($user, $data['promotion_ids'] ?? [], 'promotion', $this->attachedIds($room, $date, 'promotions'))
            ?? $this->foreignIdsError($user, $data['coupon_ids'] ?? [], 'coupon', $this->attachedIds($room, $date, 'coupons'))) {
            return response()->json(['message' => $error], 422);
        }

        $this->pricing->saveDate(
            $room,
            $date,
            isset($data['price']) ? (int) $data['price'] : null,
            $data['checkin'] ?? null,
            $data['checkout'] ?? null,
            $data['promotion_ids'] ?? [],
            $data['coupon_ids'] ?? [],
        );

        return response()->json(['date' => $this->pricing->calendar($room, $date, $date)[0]]);
    }

    public function destroy(Request $request, string $id, string $date): JsonResponse
    {
        $room = $this->visibleRoom($request->user(), $id);
        if (! $room) {
            return response()->json(['message' => 'Không tìm thấy phòng theo ngày.'], 404);
        }

        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();

        $this->pricing->clearDates($room, [$date]);

        return response()->json(['date' => $this->pricing->calendar($room, $date, $date)[0]]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Phòng theo ngày đang hoạt động mà $user quản lý (super_admin: mọi phòng). */
    private function visibleRoom(User $user, string $id): ?Product
    {
        $room = Product::where('id', $id)->where('styles', 2)->first();

        if (! $room || (! $user->isSuperAdmin() && ! $this->userCanAccessRoom($user, $room))) {
            return null;
        }

        return $room;
    }

    // allowedCategoryIds() rỗng = admin không bị giới hạn chi nhánh trong đối tác → đúng partner là đủ
    // (cùng quy ước RoomPromotionController/RoomPricingController).
    private function userCanAccessRoom(User $user, Product $room): bool
    {
        if ($room->partner_id !== $user->partner_id) {
            return false;
        }

        $allowedCategoryIds = $user->allowedCategoryIds();
        if (empty($allowedCategoryIds)) {
            return true;
        }

        return $room->categories()->whereIn('categories.id', $allowedCategoryIds)->exists();
    }

    /** null = mọi id đều nằm trong options() của user (hoặc đang gắn sẵn ở ngày đó — $keep). */
    private function foreignIdsError(User $user, array $ids, string $kind, array $keep = []): ?string
    {
        $options = $this->pricing->options($user);
        $allowed = $kind === 'coupon'
            ? array_column($options['coupons'], 'id')
            : array_merge(array_column($options['promotions'], 'id'), array_column($options['surcharges'], 'id'));

        $invalid = array_values(array_diff(array_map('intval', $ids), array_merge($allowed, $keep)));

        if ($invalid === []) {
            return null;
        }

        return ($kind === 'coupon' ? 'Mã giảm giá' : 'Khuyến mãi/phụ thu') . ' #' . implode(', #', $invalid)
            . ' không tồn tại, đã tắt/hết hạn hoặc không thuộc đối tác của bạn.';
    }

    private function attachedIds(Product $room, string $date, string $relation): array
    {
        $slot = \Modules\Product\App\Models\RoomTimeSlot::where('room_id', $room->id)
            ->whereHas('timeSlot', fn ($q) => $q->where('type', 'date')->where('label', $date))
            ->with($relation)
            ->first();

        return $slot ? $slot->{$relation}->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
    }
}
