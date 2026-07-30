<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $slots = $query->orderBy('start_time')->get(['id', 'start_time', 'end_time', 'label', 'over_night', 'type']);

        $grouped = collect(self::GROUPS)->mapWithKeys(fn ($label, $key) => [$key => []])->toArray();

        foreach ($slots as $slot) {
            $grouped[$this->classify($slot)][] = [
                'id'         => $slot->id,
                'start_time' => $slot->start_time,
                'end_time'   => $slot->end_time,
                'label'      => $slot->label,
                'over_night' => (bool) $slot->over_night,
            ];
        }

        $data = collect(self::GROUPS)->map(fn ($label, $key) => [
            'key'   => $key,
            'label' => $label,
            'slots' => $grouped[$key],
        ])->values();

        return response()->json(['data' => $data]);
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
