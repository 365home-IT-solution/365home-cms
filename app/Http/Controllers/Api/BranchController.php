<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\BuildsRoomCard;
use App\Models\ProvinceBranch;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\Livewire\Book;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

class BranchController extends Controller
{
    use BuildsRoomCard;

    /**
     * GET /api/v1/branches/{slug}/time-slots?days=15&session_id=...
     *
     * Lịch đặt phòng đầy đủ của 1 chi nhánh: mọi phòng theo khung giờ (styles=1/null) đang hoạt
     * động, cho $days ngày kể từ hôm nay (mặc định/tối đa giống hệt lịch web —
     * Book::INITIAL_VISIBLE_DAYS/MAX_VISIBLE_DAYS), kèm giá/khuyến mãi/trạng thái đã đặt cho từng
     * ô ngày x khung giờ. Tái dùng Book::calculateSlotPrice()/getDatesForOneMonth() (Livewire
     * component dùng cho lịch web) để không lặp lại logic tính giá/khuyến mãi ở 2 nơi — chỉ gọi
     * như 1 object PHP thuần (không qua vòng đời Livewire), tương tự cách BranchBookConfig dùng
     * chung cho /branch/{slug} và panel tìm kiếm.
     *
     * session_id (tuỳ chọn): truyền cùng session_id đã dùng ở
     * POST/DELETE /api/rooms/{id}/time-slot-hold để mỗi ô trả thêm held/held_by_me — client biết
     * ô nào ĐANG bị người khác chọn (held=true, held_by_me=false → is_selectable luôn false) để
     * khoá lại trên giao diện, tránh 2 người cùng chọn trùng 1 ô rồi 1 người bị từ chối lúc thanh
     * toán. Không truyền session_id thì vẫn hoạt động bình thường, chỉ không phân biệt được
     * held_by_me (mọi hold coi như "của người khác").
     */
    public function timeSlots(Request $request, string $slug): JsonResponse
    {
        $branch = Category::where('slug', $slug)->first();

        if (! $branch) {
            return response()->json(['message' => 'Chi nhánh không tồn tại.'], 404);
        }

        $days = (int) $request->query('days', Book::INITIAL_VISIBLE_DAYS);
        $days = max(1, min($days, Book::MAX_VISIBLE_DAYS));
        $sessionId = $request->query('session_id');

        $categoryIds = array_merge(
            [$branch->id],
            Category::where('parent_id', $branch->id)->pluck('id')->toArray()
        );

        $endDate   = Carbon::now()->addMonth()->endOfDay();
        $startDate = Carbon::now()->startOfDay();

        $authUser      = auth('sanctum')->user();
        $wishlistedIds = $authUser
            ? $authUser->wishlists()->pluck('product_id')->toArray()
            : null;

        $rooms = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where(fn ($q) => $q->where('styles', 1)->orWhereNull('styles'))
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->whereHas('roomTimeSlots.timeSlot')
            ->with([
                'media',
                'roomTimeSlots' => function ($query) {
                    $query->join('time_slots', 'room_time_slots.timeslot_id', '=', 'time_slots.id')
                        ->select('room_time_slots.*')
                        ->orderBy('time_slots.start_time', 'asc');
                },
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
                'orderItems' => function ($query) use ($endDate, $startDate) {
                    $query->where('checkout_date', '>', $startDate)
                        ->where('checkin_date', '<=', $endDate)
                        ->whereHas('order', fn ($sub) => $sub->whereIn('status', ['pending', 'paid', 'shipped', 'confirmed']));
                },
                'orderItems.order',
            ])
            ->orderBy('sort_order')
            ->get();

        // Instance thuần (không mount qua Livewire) — chỉ dùng các method tính toán thuần PHP
        // (calculateSlotPrice/getDatesForOneMonth), không đụng tới vòng đời request Livewire.
        $book = new Book();
        $book->visibleDaysCount = $days;
        $dates = $book->getDatesForOneMonth();
        $today = Carbon::today();

        $branchInfo = [
            'id'   => $branch->id,
            'name' => $branch->name,
            'slug' => $branch->slug,
        ];

        // Danh sách card phòng (ảnh, giá, rating...) — panel bên trái của lịch đặt phòng, tách biệt
        // khỏi lưới khung giờ x ngày (roomsData) vì 2 khối UI độc lập nhau, không phải 1-1 theo hàng.
        $roomList = $rooms->map(function (Product $room) use ($wishlistedIds, $branchInfo) {
            $wishlistStatus = $wishlistedIds === null ? null : in_array($room->id, $wishlistedIds);
            $card           = $this->mapRoom($room, $wishlistStatus);
            $card['branch'] = $branchInfo;

            return $card;
        })->values();

        $roomsData = $rooms->map(function (Product $room) use ($book, $dates, $today, $sessionId) {
            // 1 lần/phòng (không phải 1 lần/ô) — số hold đang hoạt động của 1 phòng luôn rất nhỏ
            // (vài khách đang xem cùng lúc), lookup tuyến tính trong buildSlotStatus() là đủ rẻ.
            $holds = TimeSlotHoldController::getActiveHolds((string) $room->id);

            return [
                'id'   => $room->id,
                'name' => $room->name,
                'slug' => $room->slug,
                // Đang có khuyến mãi giảm giá hiệu lực ngay bây giờ ở bất kỳ khung giờ nào — dùng
                // để hiện icon flash-sale trang trí, giống điều kiện $roomHasDiscount ở
                // book/_desktop-grid.blade.php.
                'has_discount' => $room->roomTimeSlots->contains(
                    fn ($rts) => $rts->promotions->contains(
                        fn ($p) => in_array($p->type, ['percentage', 'fixed'])
                            && $p->is_active
                            && Carbon::parse($p->start_at)->lte(now())
                            && Carbon::parse($p->end_at)->gte(now())
                    )
                ),
                'time_slots' => $room->roomTimeSlots->map(fn ($rts) => [
                    'timeslot_id' => $rts->timeslot_id,
                    'time'        => substr($rts->timeSlot->start_time, 0, 5) . ' - ' . substr($rts->timeSlot->end_time, 0, 5),
                    'over_night'  => (bool) $rts->over_night,
                ])->values(),
                'days' => collect($dates)->map(fn ($date) => [
                    'date'     => $date['date'],
                    'day'      => $date['day'],
                    'is_today' => $date['is_today'],
                    'slots'    => $room->roomTimeSlots
                        ->map(fn ($rts) => $this->buildSlotStatus($room, $rts, $date, $book, $today, $holds, $sessionId))
                        ->values(),
                ])->values(),
            ];
        })->values();

        return response()->json([
            'branch'    => $branchInfo,
            'dates'     => $dates,
            'room_list' => $roomList,
            'rooms'     => $roomsData,
        ]);
    }

    /**
     * Trạng thái + giá 1 ô "khung giờ x ngày" — cùng logic với
     * book/_slot-cell.blade.php (dùng chung cho lịch web), để 2 nơi luôn khớp nhau.
     *
     * @param  array  $holds  Hold còn hạn của cả phòng (TimeSlotHoldController::getActiveHolds()) —
     *                        lọc đúng ô này (timeslot_id + date) bên trong, không phải lọc sẵn từ
     *                        ngoài vì hàm này chạy lặp lại cho từng ô/ngày của cùng 1 phòng.
     */
    private function buildSlotStatus(Product $room, $rts, array $date, Book $book, Carbon $today, array $holds = [], ?string $sessionId = null): array
    {
        $currentDateTime = Carbon::createFromFormat(
            'd-m-Y H:i:s',
            $date['date'] . ' ' . $rts->timeSlot->start_time,
        );

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
            $slotEndDateTime   = $slotDate->copy()->setTime(
                $slotEndTimeParsed->hour,
                $slotEndTimeParsed->minute,
                $slotEndTimeParsed->second
            );
            if ($isOvernightSlot) {
                $slotEndDateTime->addDay();
            }
            if (now()->gte($slotEndDateTime)) {
                $isSelectable = false;
            }
        }

        $rtsSettings  = is_array($rts->settings) ? $rts->settings : (json_decode($rts->settings, true) ?? []);
        $blockedDates = $rtsSettings['blocked_dates'] ?? [];
        $slotDateYmd  = $slotDate->toDateString();
        $isBlocked    = in_array($slotDateYmd, $blockedDates);
        if ($isBlocked) {
            $isSelectable = false;
        }

        // "Đang được chọn" bởi 1 session khác (xem TimeSlotHoldController) — khoá lại như 1 ô đã
        // đặt, TRỪ khi chính session gọi API này đang giữ ô đó (held_by_me: vẫn cho tương tác để
        // họ có thể bỏ chọn lại chính ô mình vừa chọn).
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

    // GET /api/v1/branches
    // ?province_id={id} → chỉ lấy chi nhánh đang active tại tỉnh đó
    public function index(Request $request): JsonResponse
    {
        $query = Category::query()
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->where('status', true);

        if ($provinceId = $request->query('province_id')) {
            $branchIds = ProvinceBranch::where('province_id', $provinceId)
                ->where('status', true)
                ->pluck('categorie_id');

            $query->whereIn('id', $branchIds);
        }

        $branches = $query->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'branches' => $branches->map(fn ($branch) => $this->mapBranch($branch))->values(),
        ]);
    }

    // GET /api/v1/branches/{id}
    public function show(int $id): JsonResponse
    {
        $branch = Category::where('id', $id)
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->first();

        if (! $branch) {
            return response()->json(['message' => 'Không tìm thấy chi nhánh.'], 404);
        }

        $provinces = ProvinceBranch::where('categorie_id', $branch->id)
            ->where('status', true)
            ->with(['province', 'ward'])
            ->get()
            ->map(fn ($pb) => [
                'province_id'   => $pb->province_id,
                'province_name' => $pb->province?->name,
                'ward_code'     => $pb->ward_code,
                'ward_name'     => $pb->ward?->name,
            ])
            ->values();

        return response()->json([
            'branch' => [
                ...$this->mapBranch($branch),
                'provinces' => $provinces,
            ],
        ]);
    }

    private function mapBranch(Category $branch): array
    {
        return [
            'id'          => $branch->id,
            'name'        => $branch->name,
            'slug'        => $branch->slug,
            'description' => $branch->description,
            'image_url'   => $branch->image ? Storage::disk('public')->url($branch->image) : null,
            'total_rooms' => $this->countRooms($branch->id),
        ];
    }

    // Đếm số phòng thuộc chi nhánh: gán trực tiếp vào category chi nhánh
    // hoặc vào 1 category con (parent_id = chi nhánh) — cùng logic với BuildsRoomCard::resolveBranch
    private function countRooms(int $branchId): int
    {
        $childIds  = Category::where('parent_id', $branchId)->pluck('id');
        $allCatIds = $childIds->push($branchId);

        return Product::where('is_activated', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('category_id', $allCatIds))
            ->count();
    }
}
