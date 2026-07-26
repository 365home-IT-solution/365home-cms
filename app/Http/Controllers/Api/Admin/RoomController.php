<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\TimeSlotHoldController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BladeThemeV1\Livewire\Book;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class RoomController extends Controller
{
    private const DEFAULT_DAYS = 10;
    private const MAX_DAYS     = 60;

    private const DAY_ABBREVIATIONS = [
        0 => 'CN', 1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7',
    ];

    /**
     * GET /api/admin/rooms
     * Danh sách phòng — chỉ trả id/name/slug, dùng để admin chọn phòng khi tạo/sửa đơn
     * (POST /api/admin/orders cần room_id). Chỉ hiển thị phòng đang hoạt động (is_activated=true).
     *
     * Phạm vi hiển thị:
     *  - super_admin: TẤT CẢ phòng (mọi đối tác).
     *  - User thường: chỉ phòng thuộc đúng đối tác của mình (products.partner_id).
     *
     * Query params:
     *  - categories : slug của 1 chi nhánh CHA (xem GET /api/admin/branches) — lọc thêm chỉ lấy
     *    phòng thuộc chi nhánh đó (gồm cả danh mục con/khu vực bên trong chi nhánh). Dùng khi
     *    admin/super_admin quản lý nhiều chi nhánh cần chọn đúng 1 chi nhánh trước.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = Product::query()->where('is_activated', true);

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        if ($request->filled('categories')) {
            $branch = Category::query()
                ->whereNull('parent_id')
                ->where('category_type', 'product')
                ->where('slug', $request->string('categories'))
                ->first();

            // Slug không khớp chi nhánh nào -> trả rỗng thay vì bỏ qua filter (tránh lộ toàn bộ
            // phòng ngoài ý muốn khi admin gõ nhầm slug).
            if (! $branch) {
                return response()->json(['data' => []]);
            }

            $categoryIds = $this->expandBranchIds($branch->id);
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
        }

        $rooms = $query->orderBy('name')->get(['id', 'name', 'slug']);

        return response()->json(['data' => $rooms]);
    }

    /**
     * GET /api/admin/rooms/{id}/time-slots
     * Khung giờ (theo kiểu đặt "slot" — phòng có styles=1 hoặc null) x ngày của 1 phòng, dùng để
     * admin xem khung nào còn trống trước khi gọi POST /api/admin/orders (type=slot, slots[]).
     * CHỈ áp dụng cho phòng có khung giờ — phòng đặt theo ngày (styles=2, type=daily) trả lỗi 422
     * rõ ràng thay vì mảng rỗng (phòng loại đó không cần chọn khung giờ, chỉ cần checkin/checkout).
     *
     * Format mỗi ô khung giờ x ngày khớp HỆT app khách hàng (BranchController::buildSlotStatus())
     * — cùng tái sử dụng Book::calculateSlotPrice() (khuyến mãi) + TimeSlotHoldController::
     * getActiveHolds() (giữ chỗ tạm) để giá/khuyến mãi/trạng thái giữ chỗ hiển thị đúng khớp với
     * những gì khách đang thấy trên app, không phải 1 phiên bản tính riêng lệch nhau.
     *
     * Mặc định trả 10 NGÀY kể từ hôm nay — muốn xem thêm, gọi lại với offset_days tăng dần theo
     * đúng số ngày đã xem (không lặp lại ngày cũ), giống phân trang nhưng theo đơn vị ngày:
     *   Lần 1: (mặc định)                → ngày 0-9   (hôm nay .. hôm nay+9)
     *   Lần 2: ?offset_days=10           → ngày 10-19
     *   Lần 3: ?offset_days=20           → ngày 20-29
     *
     * Query params:
     *  - days        : số ngày muốn lấy mỗi lần gọi (mặc định 10, tối đa 60)
     *  - offset_days : số ngày bỏ qua kể từ hôm nay (mặc định 0) — dùng để "xem thêm"
     *  - session_id  : tuỳ chọn — nếu admin app cũng giữ chỗ tạm (time-slot-hold) bằng session_id
     *    riêng, truyền vào để "held_by_me" nhận đúng ô mình đang giữ.
     */
    public function timeSlots(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $room = Product::where('id', $id)
            ->where('is_activated', true)
            ->with([
                'roomTimeSlots' => fn ($q) => $q->whereNull('date'),
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->first();

        if (! $room || (! $user->isSuperAdmin() && $room->partner_id !== $user->partner_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        // styles=2 = phòng đặt theo NGÀY (type=daily) — không có khái niệm "khung giờ" lặp lại,
        // trả lỗi rõ ràng thay vì im lặng trả mảng rỗng (styles=1 hoặc null đều coi là phòng theo
        // khung giờ, khớp quy ước ở BranchController::timeSlots()/BuildsRoomBooking).
        if ((int) $room->styles === 2) {
            return response()->json(['message' => 'Phòng này đặt theo ngày (daily), không có khung giờ.'], 422);
        }

        $days       = max(1, min((int) $request->query('days', self::DEFAULT_DAYS), self::MAX_DAYS));
        $offsetDays = max(0, (int) $request->query('offset_days', 0));
        $sessionId  = $request->query('session_id');

        $startDate = Carbon::today()->addDays($offsetDays);
        $endDate   = $startDate->copy()->addDays($days - 1)->endOfDay();

        $timeSlots = $room->roomTimeSlots
            ->filter(fn ($rts) => $rts->timeSlot)
            ->sortBy(fn ($rts) => $rts->timeSlot->start_time)
            ->values();

        $roomInfo = [
            'id'   => $room->id,
            'name' => $room->name,
            'slug' => $room->slug,
        ];

        if ($timeSlots->isEmpty()) {
            return response()->json([
                'room'       => $roomInfo,
                'time_slots' => [],
                'period'     => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString(), 'days' => $days, 'offset_days' => $offsetDays],
                'days'       => [],
            ]);
        }

        // order_items có thể đụng khoảng ngày đang xem — load 1 lần cho cả phòng thay vì query lặp
        // lại cho từng ô khung giờ x ngày (N ngày x M khung giờ query riêng sẽ rất tốn).
        $room->load(['orderItems' => function ($q) use ($startDate, $endDate) {
            $q->where('checkout_date', '>', $startDate)
                ->where('checkin_date', '<=', $endDate)
                ->whereHas('order', fn ($o) => $o->whereIn('status', ['pending', 'paid', 'shipped', 'confirmed']));
        }, 'orderItems.order']);

        $holds = TimeSlotHoldController::getActiveHolds((string) $room->id);
        $book  = new Book();
        $today = Carbon::today();

        $daysOut = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $startDate->copy()->addDays($i);
            $dateArr = [
                'date'     => $date->format('d-m-Y'),
                'day'      => self::DAY_ABBREVIATIONS[$date->dayOfWeek] ?? '',
                'is_today' => $date->isToday(),
            ];

            $slots = $timeSlots
                ->map(fn ($rts) => $this->buildSlotStatus($room, $rts, $dateArr, $book, $today, $holds, $sessionId))
                ->values();

            $daysOut[] = $dateArr + ['slots' => $slots];
        }

        return response()->json([
            'room' => $roomInfo,
            'time_slots' => $timeSlots->map(fn ($rts) => [
                'timeslot_id' => $rts->timeslot_id,
                'time'        => substr($rts->timeSlot->start_time, 0, 5) . ' - ' . substr($rts->timeSlot->end_time, 0, 5),
                'over_night'  => (bool) $rts->over_night,
            ])->values(),
            'period' => ['start' => $startDate->toDateString(), 'end' => $endDate->toDateString(), 'days' => $days, 'offset_days' => $offsetDays],
            'days'   => $daysOut,
        ]);
    }

    /**
     * Trạng thái + giá 1 ô "khung giờ x ngày" — bản admin của
     * BranchController::buildSlotStatus() (khách hàng), giữ NGUYÊN công thức tính để 2 nơi luôn
     * khớp nhau, chỉ khác nguồn dữ liệu đầu vào (1 phòng theo đối tác admin, không phải mọi phòng
     * trong 1 chi nhánh public).
     */
    private function buildSlotStatus(Product $room, $rts, array $date, Book $book, Carbon $today, array $holds, ?string $sessionId): array
    {
        $currentDateTime = Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $rts->timeSlot->start_time);

        $status = 'available';
        foreach ($room->orderItems as $orderItem) {
            $checkin  = Carbon::parse($orderItem->checkin_date);
            $checkout = Carbon::parse($orderItem->checkout_date);
            if ($currentDateTime->between($checkin, $checkout)) {
                if ($orderItem->order) {
                    $status = $orderItem->order->status;
                }
                break;
            }
        }

        $isSelectable = ! in_array($status, ['pending', 'paid', 'shipped', 'confirmed']);

        $slotDate   = Carbon::createFromFormat('d-m-Y', $date['date'])->startOfDay();
        $yesterday  = now()->subDay()->startOfDay();
        $cutoffTime = now()->startOfDay()->setTime(7, 30, 0);

        if ($slotDate->lt($yesterday)) {
            $isSelectable = false;
        } elseif ($slotDate->eq($yesterday)) {
            if (now()->gte($cutoffTime)) {
                $isSelectable = false;
            }
        } elseif ($slotDate->eq($today)) {
            $slotEndTimeParsed = Carbon::parse($rts->timeSlot->end_time);
            $isOvernightSlot   = $slotEndTimeParsed->lt(Carbon::parse($rts->timeSlot->start_time));
            $slotEndDateTime   = $slotDate->copy()->setTime($slotEndTimeParsed->hour, $slotEndTimeParsed->minute, $slotEndTimeParsed->second);
            if ($isOvernightSlot) {
                $slotEndDateTime->addDay();
            }
            if (now()->gte($slotEndDateTime)) {
                $isSelectable = false;
            }
        }

        $isBlocked = $rts->isBlockedOn($slotDate->toDateString());
        if ($isBlocked) {
            $isSelectable = false;
        }

        $heldEntry = collect($holds)->first(
            fn ($h) => (int) $h['timeslot_id'] === (int) $rts->timeslot_id && $h['date'] === $date['date']
        );
        $held     = $heldEntry !== null;
        $heldByMe = $held && $sessionId !== null && $heldEntry['session_id'] === $sessionId;
        if ($held && ! $heldByMe) {
            $isSelectable = false;
        }

        $slotStartTime = Carbon::parse($rts->timeSlot->start_time)->format('H:i:s');
        $priceData     = $book->calculateSlotPrice($rts, $date['date'], $slotStartTime);

        $finalPrice    = (int) $priceData['final_price'];
        $originalPrice = (int) $priceData['original_price'];

        return [
            'timeslot_id'   => $rts->timeslot_id,
            'status'        => $status,
            'is_selectable' => $isSelectable,
            'is_blocked'    => $isBlocked,
            'held'          => $held,
            'held_by_me'    => $heldByMe,
            'price'         => $originalPrice,
            'final_price'   => $finalPrice !== $originalPrice ? $finalPrice : null,
            'has_promotion' => $priceData['has_promotion'],
            'is_increase'   => $priceData['is_increase'],
            'promotions'    => collect($priceData['promotions'])->map(fn ($p) => [
                'id'    => $p->id,
                'name'  => $p->name,
                'type'  => $p->type,
                'value' => $p->value,
                'label' => $p->lable_client,
            ])->values(),
        ];
    }

    /** Mở rộng 1 chi nhánh (branch category) ra chính nó + toàn bộ danh mục con (khu vực/tầng...). */
    private function expandBranchIds(int $branchId): array
    {
        $allIds       = [$branchId];
        $currentLevel = [$branchId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }
}
