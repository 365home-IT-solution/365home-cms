<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\RoomSlotRandomizer;
use Illuminate\Validation\Rule;
use Modules\Product\App\Models\PriceBoardTimeSlot;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Product\App\Models\PriceTemplateSlot;

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
 *
 * Phạm vi theo đối tác (time_slots.partner_id):
 *  - partner_id NULL = khung giờ MẪU hệ thống — đối tác nào cũng thấy và chọn được, nhưng chỉ
 *    super_admin (không đang chọn đối tác) được sửa/xoá.
 *  - partner_id có giá trị = khung giờ riêng đối tác đó tự tạo — chỉ đối tác đó thấy/sửa/xoá.
 *  Mỗi item trả về có is_system + editable để FE biết hiển thị nút sửa/xoá hay không.
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
     *  - scope           : system (chỉ mẫu hệ thống) | mine (chỉ khung giờ riêng) — bỏ trống = cả 2.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $query = $this->visibleQuery($user);

        if ($request->input('scope') === 'system') {
            $query->whereNull('partner_id');
        } elseif ($request->input('scope') === 'mine') {
            $query->whereNotNull('partner_id');
        }

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

        $slots = $query->orderBy('start_time')->get(['id', 'partner_id', 'start_time', 'end_time', 'label', 'over_night', 'type']);

        $groups = self::GROUPS;
        if ($request->filled('key')) {
            $groups = array_intersect_key($groups, array_flip([$request->string('key')->toString()]));
        }

        $grouped = collect($groups)->mapWithKeys(fn ($label, $key) => [$key => []])->toArray();

        foreach ($slots as $slot) {
            $groupKey = $this->classify($slot);
            if (array_key_exists($groupKey, $grouped)) {
                $grouped[$groupKey][] = $this->toItem($slot, $user);
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
     *
     * Khung giờ tạo ra thuộc về đối tác của người gọi. super_admin (không chọn đối tác) tạo ra
     * khung giờ MẪU hệ thống (partner_id NULL). Nếu đã có khung giờ cùng giờ bắt đầu/kết thúc/qua
     * đêm trong phạm vi dùng được (mẫu hệ thống hoặc của chính đối tác) → 422 kèm khung giờ đó
     * (existing) để FE chọn luôn thay vì tạo trùng.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

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

        $partnerId = $this->ownerPartnerId($user);

        $existing = TimeSlot::usableBy($partnerId)
            ->where('start_time', 'like', $data['start_time'] . '%')
            ->where('end_time', 'like', $data['end_time'] . '%')
            ->where('over_night', $overNight)
            ->where('type', $data['type'] ?? 'time')
            ->first();

        if ($existing) {
            return response()->json([
                'message'  => 'Khung giờ này đã có sẵn — chọn khung giờ có sẵn thay vì tạo mới.',
                'existing' => $this->toItem($existing, $user),
            ], 422);
        }

        $slot = TimeSlot::create([
            'partner_id' => $partnerId,
            'start_time' => $data['start_time'],
            'end_time'   => $data['end_time'],
            'over_night' => $overNight,
            'label'      => $data['label'] ?? $this->buildLabel($data['start_time'], $data['end_time'], $overNight),
            'type'       => $data['type'] ?? 'time',
        ]);

        return response()->json(['data' => $this->toItem($slot, $user)], 201);
    }

    /**
     * POST /api/admin/time-slots/random — TẠO khung giờ ngẫu nhiên cho đối tác (bước đầu của luồng
     * tạo bảng giá: random khung giờ TRƯỚC, rồi mới tạo bảng giá với các timeslot_id này).
     *
     * Body:
     *  - random_count (nullable, 2–8, mặc định 5): số khung giờ, đã gồm 1 khung qua đêm.
     *
     * Bộ khung giờ trải 1 ngày từ sáng đến tối + 1 khung qua đêm:
     *  - khung đầu bắt đầu 06:00–08:00, các khung ban ngày nối tiếp nhau, độ dài xấp xỉ nhau;
     *  - 2 khung liền nhau cách 15–30 phút;
     *  - khung qua đêm bắt đầu 21:00–23:00, kết thúc đúng giờ khung đầu tiên của hôm sau.
     * Khung trùng giờ với khung đối tác đã dùng được (hệ thống hoặc riêng) thì dùng lại (is_new =
     * false), chưa có thì tạo khung riêng của đối tác (is_new = true). suggested_price là giá gợi
     * ý để FE điền sẵn khi tạo bảng giá (ban ngày 100.000–300.000đ, qua đêm 250.000–450.000đ).
     *
     * Bắt buộc có đối tác: tài khoản đối tác dùng đối tác của mình; super_admin gửi X-Partner-Id.
     */
    public function random(Request $request, RoomSlotRandomizer $randomizer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->seesAllPartners()) {
            return response()->json(['message' => 'Chọn đối tác (header X-Partner-Id) để tạo khung giờ ngẫu nhiên cho đối tác đó.'], 422);
        }

        if (empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản chưa thuộc đối tác nào.'], 403);
        }

        $data = $request->validate([
            'random_count' => ['nullable', 'integer', 'min:' . RoomSlotRandomizer::MIN_COUNT, 'max:' . RoomSlotRandomizer::MAX_COUNT],
        ]);

        $picked = $randomizer->pick((string) $user->partner_id, (int) ($data['random_count'] ?? RoomSlotRandomizer::DEFAULT_COUNT));

        return $this->slotsResponse($picked);
    }

    /** Response chung của 2 cách tạo khung giờ (random / bộ mẫu). */
    private function slotsResponse(array $picked): JsonResponse
    {
        $slots = TimeSlot::whereIn('id', array_column($picked['slots'], 'timeslot_id'))->get()->keyBy('id');

        return response()->json([
            'message' => $picked['created'] > 0
                ? 'Đã tạo ' . $picked['created'] . ' khung giờ mới.'
                : 'Các khung giờ đã có sẵn, dùng lại khung giờ cũ.',
            'data'    => [
                'random_count' => count($picked['slots']),
                'created'      => $picked['created'],
                'slots'        => array_map(fn (array $row) => [
                    'id'              => $row['timeslot_id'],
                    'label'           => $slots[$row['timeslot_id']]->label,
                    'start_time'      => $slots[$row['timeslot_id']]->start_time,
                    'end_time'        => $slots[$row['timeslot_id']]->end_time,
                    'over_night'      => $row['over_night'],
                    'is_new'          => $row['is_new'],
                    'suggested_price' => $row['price'],
                ], $picked['slots']),
            ],
        ], 201);
    }

    /**
     * PUT/PATCH /api/admin/time-slots/{id}
     * Sửa khung giờ — LƯU Ý: khung giờ này có thể đang được nhiều phòng dùng chung (qua
     * room_time_slots.timeslot_id), sửa ở đây ảnh hưởng giờ/nhãn hiển thị ở TẤT CẢ phòng đó. Field
     * nào không có mặt trong request giữ nguyên giá trị cũ. Mẫu hệ thống → 403 với đối tác (muốn
     * giờ khác thì tạo khung giờ riêng).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $slot = $this->visibleQuery($user)->find($id);

        if (! $slot) {
            return response()->json(['message' => 'Không tìm thấy khung giờ.'], 404);
        }

        if (! $this->canEdit($slot, $user)) {
            return response()->json(['message' => 'Khung giờ mẫu của hệ thống — không thể sửa. Hãy tạo khung giờ riêng.'], 403);
        }

        $data = $request->validate([
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time'   => 'sometimes|required|date_format:H:i',
            'over_night' => 'nullable|boolean',
            'label'      => 'nullable|string|max:255',
            'type'       => 'nullable|in:time,date',
        ]);

        $slot->update($data);

        return response()->json(['data' => $this->toItem($slot->fresh(), $user)]);
    }

    /**
     * DELETE /api/admin/time-slots/{id}
     * Chặn xoá nếu còn phòng nào đang gán khung giờ này (room_time_slots.timeslot_id) — xoá sẽ làm
     * mất giá/lịch sử đặt phòng đang tham chiếu tới. Cũng chặn nếu còn bảng giá mẫu dùng tới.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $slot = $this->visibleQuery($user)->find($id);

        if (! $slot) {
            return response()->json(['message' => 'Không tìm thấy khung giờ.'], 404);
        }

        if (! $this->canEdit($slot, $user)) {
            return response()->json(['message' => 'Khung giờ mẫu của hệ thống — không thể xoá.'], 403);
        }

        if (RoomTimeSlot::where('timeslot_id', $id)->exists()) {
            return response()->json(['message' => 'Không thể xoá — đang có phòng gán khung giờ này.'], 422);
        }

        if (PriceTemplateSlot::where('timeslot_id', $id)->exists()) {
            return response()->json(['message' => 'Không thể xoá — đang có bảng giá mẫu dùng khung giờ này.'], 422);
        }

        if (PriceBoardTimeSlot::where('timeslot_id', $id)->exists()) {
            return response()->json(['message' => 'Không thể xoá — đang có bảng giá dùng khung giờ này.'], 422);
        }

        $slot->delete();

        return response()->json(['message' => 'Đã xoá khung giờ.']);
    }

    private function buildLabel(string $startTime, string $endTime, bool $overNight): string
    {
        $label = "{$startTime} - {$endTime}";

        return $overNight ? "{$label} (Qua đêm)" : $label;
    }

    /** super_admin không chọn đối tác thấy mọi khung giờ (mẫu + của mọi đối tác); còn lại chỉ thấy
     *  mẫu hệ thống + khung giờ của đối tác mình. */
    private function visibleQuery(User $user)
    {
        return $user->seesAllPartners() ? TimeSlot::query() : TimeSlot::usableBy($user->partner_id);
    }

    /** Đối tác sở hữu khung giờ mới tạo — NULL (mẫu hệ thống) khi super_admin không chọn đối tác. */
    private function ownerPartnerId(User $user): ?string
    {
        return $user->seesAllPartners() ? null : $user->partner_id;
    }

    private function canEdit(TimeSlot $slot, User $user): bool
    {
        if ($user->seesAllPartners()) {
            return true;
        }

        return $slot->partner_id !== null && $slot->partner_id === $user->partner_id;
    }

    private function toItem(TimeSlot $slot, User $user): array
    {
        return [
            'id'         => $slot->id,
            'start_time' => $slot->start_time,
            'end_time'   => $slot->end_time,
            'label'      => $slot->label,
            'over_night' => (bool) $slot->over_night,
            'type'       => $slot->type,
            'partner_id' => $slot->partner_id,
            'is_system'  => $slot->isSystem(),
            'editable'   => $this->canEdit($slot, $user),
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
