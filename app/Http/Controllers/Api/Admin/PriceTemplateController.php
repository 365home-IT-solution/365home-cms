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
use RuntimeException;

/**
 * BẢNG GIÁ MẪU — bộ khung giờ + giá có sẵn để đối tác chọn khi tạo bảng giá (bảng price_templates/
 * price_template_slots). Là NGUỒN khung giờ + giá, không ghi thẳng xuống phòng: dùng mẫu bằng cách TẠO
 * BẢNG GIÁ từ mẫu (POST /{id}/create-board) rồi bảng giá (price_boards) mới đổi giá phòng theo lịch.
 *
 * Luồng chọn mẫu:
 *  1. GET  /api/admin/price-templates                  → danh sách mẫu hệ thống + mẫu riêng của đối tác.
 *  2. POST /api/admin/price-templates/{id}/create-board → tạo bảng giá cho chi nhánh + phòng đã chọn.
 *  3. Muốn chỉnh mẫu: PUT /api/admin/price-templates/{id}. Mẫu hệ thống (partner_id NULL) thì đối tác
 *     không sửa trực tiếp được — API tự TÁCH thành bản riêng của đối tác (source_template_id trỏ về mẫu
 *     gốc) rồi áp thay đổi lên bản riêng đó; sửa tiếp lần sau (dù FE vẫn gửi id mẫu gốc) sẽ cập nhật
 *     đúng bản riêng đã tách, không tạo thêm bản mới.
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
     * Thêm THẲNG khung giờ + giá của mẫu vào các phòng đã có (phòng theo khung giờ, styles=1) — cùng
     * cách khi chọn bảng giá mẫu lúc tạo phòng (POST /api/admin/products, price_template_id).
     * Body: room_ids (required, ≥1), timeslot_ids[] (tuỳ chọn: chỉ thêm các khung giờ này của mẫu, bỏ
     * trống = cả mẫu), mode: replace (mặc định) | merge.
     *  - replace: phòng chỉ còn đúng các khung giờ của mẫu (khung cũ không có trong mẫu bị gỡ).
     *  - merge  : chỉ thêm/cập nhật khung giờ có trong mẫu, giữ các khung khác.
     * Muốn đổi giá theo lịch/ngày hiệu lực thì dùng create-board (tạo bảng giá) thay vì apply.
     */
    public function apply(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->with('slots')->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        if ($user->seesAllPartners() || empty($user->partner_id)) {
            return response()->json(['message' => 'Chọn đối tác (header X-Partner-Id) để áp bảng giá mẫu cho phòng.'], 422);
        }

        $data = $request->validate([
            'room_ids'   => 'required|array|min:1',
            'room_ids.*' => 'required|string',
            'mode'       => 'nullable|in:replace,merge',
            'timeslot_ids'   => 'nullable|array|min:1',
            'timeslot_ids.*' => 'integer',
        ]);

        $allowed = $user->allowedCategoryIds();
        $rooms   = \Modules\Product\App\Models\Product::query()
            ->whereIn('id', $data['room_ids'])
            ->where('partner_id', $user->partner_id)
            ->when(! empty($allowed), fn ($q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $allowed)))
            ->get();

        $missing = array_diff(array_unique($data['room_ids']), $rooms->pluck('id')->map(fn ($v) => (string) $v)->all());
        if ($missing) {
            return response()->json(['message' => 'Không tìm thấy phòng: ' . implode(', ', $missing) . '.'], 404);
        }

        foreach ($rooms as $room) {
            if ($error = $this->templates->applyError($template, (string) $user->partner_id, $room, $data['timeslot_ids'] ?? null)) {
                return response()->json(['message' => $error . " ({$room->name})"], 422);
            }
        }

        foreach ($rooms as $room) {
            $this->templates->applyToRoom($template, $room, ($data['mode'] ?? 'replace') === 'replace', $data['timeslot_ids'] ?? null);
        }

        return response()->json([
            'message'  => 'Đã thêm bảng giá mẫu vào ' . $rooms->count() . ' phòng.',
            'room_ids' => $rooms->pluck('id')->values(),
        ]);
    }

    /**
     * POST /api/admin/price-templates/{id}/create-board
     * TẠO BẢNG GIÁ (price_boards) từ mẫu cho 1 chi nhánh + các phòng đã chọn — cách "chọn trong bảng giá
     * mẫu" khi tạo bảng giá. Phòng nhận đúng khung giờ + giá của mẫu; giá phòng chỉ đổi theo hiệu lực
     * của bảng giá (bật/tắt, ngày bắt đầu/kết thúc), mẫu KHÔNG ghi thẳng xuống phòng.
     * Body:
     *  - branch_id (required): chi nhánh áp dụng.
     *  - product_ids (required, ≥1): phòng theo khung giờ (styles=1) thuộc chi nhánh.
     *  - name (mặc định tên mẫu), note, start_date, end_date (Y-m-d), is_active (mặc định true).
     * Response 201: bảng giá vừa tạo (cùng cấu trúc GET /api/admin/price-boards/{id}).
     * Muốn chỉnh giá sau khi tạo thì sửa bảng giá đó (PUT /api/admin/price-boards/{id}); sửa mẫu về sau
     * KHÔNG làm đổi bảng giá đã tạo.
     */
    public function createBoard(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user     = $request->user();
        $template = $this->visibleQuery($user)->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy bảng giá mẫu.'], 404);
        }

        $data = $request->validate([
            'branch_id'     => 'required|integer',
            'product_ids'   => 'required|array|min:1',
            'product_ids.*' => 'required|string',
            'name'          => 'nullable|string|max:255',
            'note'          => 'nullable|string',
            'start_date'    => 'nullable|date_format:Y-m-d',
            'end_date'      => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'is_active'     => 'nullable|boolean',
        ]);

        try {
            $board = $this->templates->createBoard($template, $user, $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Đã tạo bảng giá từ mẫu cho ' . $board->items->count() . ' phòng.',
            'data'    => ['id' => $board->id, 'name' => $board->name, 'branch_id' => $board->category_id,
                'is_active' => (bool) $board->is_active, 'rooms_count' => $board->items->count()],
        ], 201);
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
