<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\PriceTemplateSlot;
use Modules\Product\App\Models\Product;

/**
 * BẢNG GIÁ MẪU — bộ khung giờ + giá có sẵn để đối tác chọn rồi áp xuống phòng (bảng
 * price_templates/price_template_slots). KHÁC price_boards (bảng giá THEO LỊCH, ghi đè giá phòng
 * trong 1 khoảng ngày rồi tự khôi phục — vẫn quản lý ở Filament).
 *
 * Luồng dùng:
 *  1. GET  /api/admin/price-templates           → danh sách mẫu hệ thống + mẫu riêng của đối tác.
 *  2. POST /api/admin/price-templates/{id}/apply → copy khung giờ + giá của mẫu xuống
 *     room_time_slots của 1 hoặc nhiều phòng. Từ đây phòng sửa giá độc lập (rooms/{id}/pricing),
 *     sửa mẫu về sau KHÔNG tự đổi giá phòng đã áp.
 *  3. Muốn chỉnh mẫu: PUT /api/admin/price-templates/{id}. Mẫu hệ thống (partner_id NULL) thì đối
 *     tác không sửa trực tiếp được — API tự TÁCH thành bản riêng của đối tác (source_template_id trỏ
 *     về mẫu gốc) rồi áp thay đổi lên bản riêng đó; sửa tiếp lần sau (dù FE vẫn gửi id mẫu gốc) sẽ
 *     cập nhật đúng bản riêng đã tách, không tạo thêm bản mới.
 *  4. Hoặc tạo mẫu mới hoàn toàn: POST /api/admin/price-templates.
 *
 * Mẫu hệ thống chỉ được dùng khung giờ hệ thống (time_slots.partner_id NULL); mẫu của đối tác dùng
 * được khung giờ hệ thống + khung giờ riêng của đối tác đó.
 */
class PriceTemplateController extends Controller
{
    public function __construct(private PriceTemplateService $templates)
    {
    }

    /**
     * GET /api/admin/price-templates
     * Query params:
     *  - scope     : system (chỉ mẫu hệ thống) | mine (chỉ mẫu riêng) — bỏ trống = cả 2.
     *  - search    : lọc theo tên.
     *  - is_active : 1/0 — bỏ trống = chỉ mẫu đang bật.
     * Đối tác đã tách bản riêng từ 1 mẫu hệ thống thì mẫu hệ thống đó bị ẩn khỏi danh sách (chỉ hiện
     * bản riêng) — tránh 2 mẫu gần trùng nhau.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $query = $this->visibleQuery($user)->with('slots.timeSlot');

        if ($request->input('scope') === 'system') {
            $query->whereNull('partner_id');
        } elseif ($request->input('scope') === 'mine') {
            $query->whereNotNull('partner_id');
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        $query->where('is_active', $request->has('is_active') ? $request->boolean('is_active') : true);

        if (! $user->seesAllPartners() && $user->partner_id) {
            $forkedIds = PriceTemplate::where('partner_id', $user->partner_id)
                ->whereNotNull('source_template_id')
                ->pluck('source_template_id');

            $query->whereNotIn('id', $forkedIds);
        }

        $templates = $query->orderByRaw('partner_id IS NULL')->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $templates->map(fn (PriceTemplate $t) => $this->toItem($t, $user))->values()]);
    }

    /** GET /api/admin/price-templates/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->with('slots.timeSlot')->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        return response()->json(['data' => $this->toItem($template, $user)]);
    }

    /**
     * POST /api/admin/price-templates
     * Body:
     *  - name (required), note, is_active (mặc định true), sort_order
     *  - slots (required, ≥1): [{ timeslot_id (required), price, checkin (H:i), checkout (H:i),
     *    over_night }] — thứ tự trong mảng = thứ tự hiển thị.
     * Mẫu thuộc đối tác của người gọi; super_admin không chọn đối tác → tạo mẫu hệ thống.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate($this->rules(creating: true));

        $partnerId = $user->seesAllPartners() ? null : $user->partner_id;

        if ($error = $this->templates->foreignTimeSlotError($partnerId, array_column($data['slots'], 'timeslot_id'))) {
            return response()->json(['message' => $error], 422);
        }

        $template = DB::transaction(function () use ($data, $partnerId) {
            $template = PriceTemplate::create([
                'partner_id' => $partnerId,
                'name'       => $data['name'],
                'note'       => $data['note'] ?? null,
                'is_active'  => $data['is_active'] ?? true,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            $this->templates->replaceSlots($template, $data['slots']);

            return $template;
        });

        return response()->json(['data' => $this->toItem($template->load('slots.timeSlot'), $user)], 201);
    }

    /**
     * PUT|PATCH /api/admin/price-templates/{id}
     * Body như POST nhưng mọi field đều tuỳ chọn; có gửi 'slots' thì THAY TOÀN BỘ danh sách khung
     * giờ của mẫu, không gửi thì giữ nguyên.
     *
     * Đối tác sửa mẫu hệ thống → tự tách bản riêng (xem docblock class), response có
     * forked_from_id = id mẫu gốc và data.id = id bản riêng — FE nên dùng id mới này từ đây.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->with('slots')->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        $data = $request->validate($this->rules(creating: false));

        $mustFork = ! $this->canEdit($template, $user);

        if ($mustFork && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản chưa thuộc đối tác nào — không thể sửa bảng giá mẫu.'], 403);
        }

        $targetPartnerId = $mustFork ? $user->partner_id : $template->partner_id;

        if (array_key_exists('slots', $data)
            && $error = $this->templates->foreignTimeSlotError($targetPartnerId, array_column($data['slots'], 'timeslot_id'))) {
            return response()->json(['message' => $error], 422);
        }

        $forkedFrom = null;

        if ($mustFork) {
            $forkedFrom = $template;
            $template   = $this->templates->forkFor($template, $user->partner_id);
        }

        DB::transaction(function () use ($template, $data) {
            $template->update(collect($data)->only(['name', 'note', 'is_active', 'sort_order'])->all());

            if (array_key_exists('slots', $data)) {
                $this->templates->replaceSlots($template, $data['slots']);
            }
        });

        return response()->json([
            'data'           => $this->toItem($template->fresh('slots.timeSlot'), $user),
            'forked_from_id' => $forkedFrom?->id,
        ]);
    }

    /**
     * DELETE /api/admin/price-templates/{id}
     * Chỉ xoá được mẫu của chính đối tác (super_admin xoá được cả mẫu hệ thống). Phòng đã áp mẫu
     * không bị ảnh hưởng (giá đã copy sang room_time_slots).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        if (! $this->canEdit($template, $user)) {
            return response()->json(['message' => 'Bảng giá mẫu của hệ thống — không thể xoá.'], 403);
        }

        $template->delete();

        return response()->json(['message' => 'Đã xoá bảng giá mẫu.']);
    }

    /**
     * POST /api/admin/price-templates/{id}/apply
     * Áp khung giờ + giá của mẫu xuống phòng (chỉ phòng theo khung giờ — styles=1).
     * Body:
     *  - room_ids (required, ≥1): id các phòng cần áp.
     *  - mode: replace (mặc định) | merge
     *      replace — phòng chỉ còn đúng các khung giờ trong mẫu; khung giờ cũ không có trong mẫu bị
     *                gỡ (kèm ưu đãi/mã giảm giá đang gắn vào khung giờ đó).
     *      merge   — thêm/cập nhật giá các khung giờ trong mẫu, giữ nguyên khung giờ khác của phòng.
     * Khung giờ đã có trên phòng (cùng timeslot_id) được CẬP NHẬT tại chỗ, giữ nguyên id → giữ nguyên
     * ưu đãi (rooms/{id}/promotions) đã gắn.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        $data = $request->validate([
            'room_ids'   => 'required|array|min:1',
            'room_ids.*' => 'required|string',
            'mode'       => 'nullable|in:replace,merge',
        ]);

        $rooms   = $this->visibleRooms($user, $data['room_ids']);
        $missing = array_diff(array_unique($data['room_ids']), $rooms->pluck('id')->map(fn ($v) => (string) $v)->all());

        if ($missing) {
            return response()->json(['message' => 'Không tìm thấy phòng: ' . implode(', ', $missing) . '.'], 404);
        }

        if ($error = $this->templates->applyError($template, $rooms)) {
            return response()->json(['message' => $error], 422);
        }

        $this->templates->apply($template, $rooms, ($data['mode'] ?? 'replace') === 'replace');

        return response()->json([
            'message'  => 'Đã áp bảng giá mẫu cho ' . $rooms->count() . ' phòng.',
            'room_ids' => $rooms->pluck('id')->values(),
        ]);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes|required';

        return [
            'name'               => "{$req}|string|max:255",
            'note'               => 'nullable|string|max:2000',
            'is_active'          => 'nullable|boolean',
            'sort_order'         => 'nullable|integer|min:0',
            'slots'              => "{$req}|array|min:1",
            'slots.*.timeslot_id' => 'required|integer|distinct|exists:time_slots,id',
            'slots.*.price'      => 'nullable|integer|min:0',
            'slots.*.checkin'    => 'nullable|date_format:H:i',
            'slots.*.checkout'   => 'nullable|date_format:H:i',
            'slots.*.over_night' => 'nullable|boolean',
        ];
    }

    private function visibleQuery(User $user)
    {
        return $user->seesAllPartners() ? PriceTemplate::query() : PriceTemplate::usableBy($user->partner_id);
    }

    private function canEdit(PriceTemplate $template, User $user): bool
    {
        if ($user->seesAllPartners()) {
            return true;
        }

        return $template->partner_id !== null && $template->partner_id === $user->partner_id;
    }

    /** Cùng phạm vi với RoomTimeSlotController::visibleProduct() — đối tác + chi nhánh được giao. */
    private function visibleRooms(User $user, array $ids)
    {
        $query = Product::query()->whereIn('id', $ids);

        if (! $user->seesAllPartners()) {
            $query->where('partner_id', $user->partner_id);

            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds));
            }
        }

        return $query->get();
    }

    private function toItem(PriceTemplate $template, User $user): array
    {
        return [
            'id'                 => $template->id,
            'name'               => $template->name,
            'note'               => $template->note,
            'is_active'          => $template->is_active,
            'sort_order'         => $template->sort_order,
            'partner_id'         => $template->partner_id,
            'source_template_id' => $template->source_template_id,
            'is_system'          => $template->isSystem(),
            'editable'           => $this->canEdit($template, $user),
            'slots'              => $template->slots->map(fn (PriceTemplateSlot $slot) => [
                'id'          => $slot->id,
                'timeslot_id' => $slot->timeslot_id,
                'price'       => $slot->price,
                'checkin'     => $slot->checkin,
                'checkout'    => $slot->checkout,
                'over_night'  => $slot->over_night,
                'time_slot'   => $slot->timeSlot ? [
                    'id'         => $slot->timeSlot->id,
                    'start_time' => $slot->timeSlot->start_time,
                    'end_time'   => $slot->timeSlot->end_time,
                    'label'      => $slot->timeSlot->label,
                ] : null,
            ])->values(),
            'created_at'         => $template->created_at?->toIso8601String(),
            'updated_at'         => $template->updated_at?->toIso8601String(),
        ];
    }
}
