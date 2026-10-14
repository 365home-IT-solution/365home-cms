<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;

/**
 * Quản lý khung giờ MẶC ĐỊNH (template) của 1 phòng cụ thể — bảng `room_time_slots`, các dòng
 * `date IS NULL` (dòng có `date` là override giá cho 1 ngày cụ thể, KHÔNG thuộc phạm vi controller
 * này). Khác với RoomController::timeSlots() (tra cứu tình trạng còn trống theo ngày để đặt phòng),
 * controller này phục vụ màn CẤU HÌNH: phòng nào áp dụng khung giờ nào, giá bao nhiêu.
 */
class RoomTimeSlotController extends Controller
{
    /**
     * GET /api/admin/products/{id}/time-slots
     * Danh sách khung giờ đã gán cho phòng: room_id, timeslot_id, price, over_night (kèm thông tin
     * khung giờ start_time/end_time/label để FE hiển thị luôn không cần gọi thêm GET /api/admin/time-slots).
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $product = $this->visibleProduct($request->user(), $id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $slots = $product->roomTimeSlots()
            ->whereNull('date')
            ->with('timeSlot')
            ->get();

        return response()->json(['data' => $slots->map(fn (RoomTimeSlot $rts) => $this->toItem($rts))->values()]);
    }

    /**
     * POST /api/admin/products/{id}/time-slots
     * Thêm/cập nhật khung giờ áp dụng cho phòng — nhận mảng slots[], mỗi phần tử ứng với 1 khung
     * giờ trong danh sách chung (GET /api/admin/time-slots). Ghi đè (updateOrCreate) theo cặp
     * (room_id, timeslot_id, date=null) — gọi lại với cùng timeslot_id sẽ CẬP NHẬT giá/over_night
     * thay vì tạo trùng dòng mới.
     *
     * Body: { "slots": [ { "timeslot_id": 1, "price": 150000, "over_night": false,
     *                       "checkin": "08:00", "checkout": "10:00" }, ... ] }
     */
    public function store(Request $request, string $id, PriceBoardSyncService $priceBoards): JsonResponse
    {
        $product = $this->visibleProduct($request->user(), $id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
            'slots'                     => 'required|array|min:1',
            'slots.*.timeslot_id'       => 'required|integer|exists:time_slots,id',
            'slots.*.price'             => 'nullable|integer|min:0',
            'slots.*.over_night'        => 'nullable|boolean',
            'slots.*.checkin'           => 'nullable|date_format:H:i',
            'slots.*.checkout'          => 'nullable|date_format:H:i',
        ]);

        if ($error = self::foreignTimeSlotError($product, array_column($data['slots'], 'timeslot_id'))) {
            return response()->json(['message' => $error], 422);
        }

        $before = $priceBoards->snapshotPricing($product);

        DB::transaction(function () use ($data, $product) {
            foreach ($data['slots'] as $row) {
                RoomTimeSlot::updateOrCreate(
                    ['room_id' => $product->id, 'timeslot_id' => $row['timeslot_id'], 'date' => null],
                    [
                        'price'      => $row['price'] ?? null,
                        'over_night' => $row['over_night'] ?? false,
                        'checkin'    => $row['checkin'] ?? null,
                        'checkout'   => $row['checkout'] ?? null,
                        'status'     => 'available',
                    ]
                );
            }
        });

        $priceBoards->afterManualPriceEdit($product, $before);

        $slots = $product->roomTimeSlots()->whereNull('date')->with('timeSlot')->get();

        return response()->json(['data' => $slots->map(fn (RoomTimeSlot $rts) => $this->toItem($rts))->values()], 201);
    }

    /** Phòng chỉ được dùng khung giờ mẫu hệ thống hoặc khung giờ riêng của ĐÚNG đối tác sở hữu
     *  phòng (time_slots.partner_id) — chặn gán nhầm khung giờ riêng của đối tác khác. Dùng chung với
     *  RoomPricingController. Trả về thông báo lỗi, hoặc null nếu hợp lệ. */
    public static function foreignTimeSlotError(Product $room, array $timeslotIds): ?string
    {
        $timeslotIds = array_values(array_unique(array_map('intval', $timeslotIds)));
        if (! $timeslotIds) {
            return null;
        }

        $usable  = TimeSlot::usableBy($room->partner_id)->whereIn('id', $timeslotIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $foreign = array_diff($timeslotIds, $usable);

        return $foreign
            ? 'Khung giờ #' . implode(', #', $foreign) . ' không thuộc đối tác của phòng này.'
            : null;
    }

    private function visibleProduct(User $user, string $id): ?Product
    {
        $query = Product::query();

        if (! $user->seesAllPartners()) {
            $query->where('partner_id', $user->partner_id);

            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds));
            }
        }

        return $query->find($id);
    }

    private function toItem(RoomTimeSlot $rts): array
    {
        return [
            'id'          => $rts->id,
            'room_id'     => $rts->room_id,
            'timeslot_id' => $rts->timeslot_id,
            'price'       => $rts->price,
            'over_night'  => (bool) $rts->over_night,
            'checkin'     => $rts->checkin,
            'checkout'    => $rts->checkout,
            'time_slot'   => $rts->timeSlot ? [
                'id'         => $rts->timeSlot->id,
                'start_time' => $rts->timeSlot->start_time,
                'end_time'   => $rts->timeSlot->end_time,
                'label'      => $rts->timeSlot->label,
            ] : null,
        ];
    }
}
