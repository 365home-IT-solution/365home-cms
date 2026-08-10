<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;

/**
 * Danh sách khung giờ dùng chung (bảng `time_slots`) — dữ liệu nền để admin gán khung giờ cho
 * từng phòng (xem RoomTimeSlotController). Kết quả được chia sẵn thành 4 nhóm để FE hiển thị dạng
 * tab/section: Ngày, Chiều, Đêm, Qua đêm — thay vì FE phải tự phân loại lại theo start_time.
 *
 * Quy tắc phân nhóm:
 *  - `over_night = true` → luôn xếp vào "Qua đêm", bất kể start_time (đây là mục đích chính xác
 *    của cột over_night sẵn có trên bảng).
 *  - Còn lại, phân theo giờ bắt đầu (start_time): 06:00–11:59 = Ngày, 12:00–17:59 = Chiều,
 *    18:00–05:59 = Đêm.
 */
class TimeSlotController extends Controller
{
    private const GROUPS = [
        'ngay'    => 'Ngày',
        'chieu'   => 'Chiều',
        'dem'     => 'Đêm',
        'qua_dem' => 'Qua đêm',
    ];

    /**
     * GET /api/admin/time-slots
     * Query params:
     *  - type            : lọc theo type ('time'/'date'...) — mặc định BỎ QUA type='date' (những
     *                       dòng đó là mốc ngày cho phòng kiểu "theo ngày", không phải khung giờ thật).
     *  - include_date_type: truyền 1 để lấy luôn cả loại 'date'.
     *  - key             : lọc theo nhóm (ngay|chieu|dem|qua_dem) — chỉ trả về nhóm đó, vd
     *                      ?key=ngay. Phân loại dựa trên cùng quy tắc classify() ở dưới.
     *  - label           : lọc gần đúng (LIKE) theo label, vd ?label=18:00.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TimeSlot::query();

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        } elseif (! $request->boolean('include_date_type')) {
            $query->where(function ($q) {
                $q->whereNull('type')->orWhere('type', '!=', 'date');
            });
        }

        if ($request->filled('label')) {
            $query->where('label', 'like', '%'.$request->string('label').'%');
        }

        $slots = $query->orderBy('start_time')->get(['id', 'start_time', 'end_time', 'label', 'over_night', 'type']);

        $groups = self::GROUPS;
        if ($request->filled('key')) {
            $groups = array_intersect_key($groups, array_flip([$request->string('key')->toString()]));
        }

        $grouped = collect($groups)->mapWithKeys(fn ($label, $key) => [$key => []])->toArray();

        foreach ($slots as $slot) {
            $groupKey = $this->classify($slot);
            if (array_key_exists($groupKey, $grouped)) {
                $grouped[$groupKey][] = [
                    'id'         => $slot->id,
                    'start_time' => $slot->start_time,
                    'end_time'   => $slot->end_time,
                    'label'      => $slot->label,
                    'over_night' => (bool) $slot->over_night,
                ];
            }
        }

        $data = collect($groups)->map(fn ($label, $key) => [
            'key'   => $key,
            'label' => $label,
            'slots' => $grouped[$key],
        ])->values();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/admin/time-slots
     * Tạo 1 khung giờ dùng chung mới. Body:
     *  - start_time, end_time (required, H:i)
     *  - over_night (nullable|boolean) — không truyền thì tự suy ra: end_time < start_time = qua đêm.
     *  - label (nullable|string|max:255) — không truyền thì tự sinh "H:i - H:i" (+ " (Qua đêm)" nếu
     *    over_night), khớp định dạng dữ liệu hiện có.
     *  - type (nullable|in:time,date) — mặc định 'time'. 'date' dành riêng cho mốc ngày của phòng
     *    "theo ngày" (styles=2), thường không cần tạo thủ công qua đây.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'over_night' => 'nullable|boolean',
            'label'      => 'nullable|string|max:255',
            'type'       => 'nullable|in:time,date',
        ]);

        $overNight = array_key_exists('over_night', $data)
            ? $data['over_night']
            : $data['end_time'] < $data['start_time'];

        $slot = TimeSlot::create([
            'start_time' => $data['start_time'],
            'end_time'   => $data['end_time'],
            'over_night' => $overNight,
            'label'      => $data['label'] ?? $this->buildLabel($data['start_time'], $data['end_time'], $overNight),
            'type'       => $data['type'] ?? 'time',
        ]);

        return response()->json(['data' => $this->toItem($slot)], 201);
    }

    /**
     * PUT/PATCH /api/admin/time-slots/{id}
     * Sửa khung giờ dùng chung — LƯU Ý: khung giờ này có thể đang được nhiều phòng khác nhau dùng
     * chung (qua room_time_slots.timeslot_id), sửa ở đây ảnh hưởng giờ/nhãn hiển thị ở TẤT CẢ phòng
     * đó. Field nào không có mặt trong request giữ nguyên giá trị cũ.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $slot = TimeSlot::find($id);

        if (! $slot) {
            return response()->json(['message' => 'Không tìm thấy khung giờ.'], 404);
        }

        $data = $request->validate([
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time'   => 'sometimes|required|date_format:H:i',
            'over_night' => 'nullable|boolean',
            'label'      => 'nullable|string|max:255',
            'type'       => 'nullable|in:time,date',
        ]);

        $slot->update($data);

        return response()->json(['data' => $this->toItem($slot->fresh())]);
    }

    /**
     * DELETE /api/admin/time-slots/{id}
     * Chặn xoá nếu còn phòng nào đang gán khung giờ này (room_time_slots.timeslot_id) — xoá sẽ làm
     * mất giá/lịch sử đặt phòng đang tham chiếu tới.
     */
    public function destroy(int $id): JsonResponse
    {
        $slot = TimeSlot::find($id);

        if (! $slot) {
            return response()->json(['message' => 'Không tìm thấy khung giờ.'], 404);
        }

        if (RoomTimeSlot::where('timeslot_id', $id)->exists()) {
            return response()->json(['message' => 'Không thể xoá — đang có phòng gán khung giờ này.'], 422);
        }

        $slot->delete();

        return response()->json(['message' => 'Đã xoá khung giờ.']);
    }

    private function buildLabel(string $startTime, string $endTime, bool $overNight): string
    {
        $label = "{$startTime} - {$endTime}";

        return $overNight ? "{$label} (Qua đêm)" : $label;
    }

    private function toItem(TimeSlot $slot): array
    {
        return [
            'id'         => $slot->id,
            'start_time' => $slot->start_time,
            'end_time'   => $slot->end_time,
            'label'      => $slot->label,
            'over_night' => (bool) $slot->over_night,
            'type'       => $slot->type,
        ];
    }

    private function classify(TimeSlot $slot): string
    {
        if ($slot->over_night) {
            return 'qua_dem';
        }

        if (empty($slot->start_time)) {
            return 'qua_dem';
        }

        $hour = (int) substr($slot->start_time, 0, 2);

        if ($hour >= 6 && $hour < 12) {
            return 'ngay';
        }

        if ($hour >= 12 && $hour < 18) {
            return 'chieu';
        }

        return 'dem';
    }
}
