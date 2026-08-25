<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

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
    public function store(Request $request, string $id): JsonResponse
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

        $slots = $product->roomTimeSlots()->whereNull('date')->with('timeSlot')->get();

        return response()->json(['data' => $slots->map(fn (RoomTimeSlot $rts) => $this->toItem($rts))->values()], 201);
    }

    private function visibleProduct(User $user, string $id): ?Product
    {
        $query = Product::query();

        if (! $user->isSuperAdmin()) {
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
