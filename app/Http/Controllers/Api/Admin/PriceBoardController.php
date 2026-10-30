<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use App\Services\PriceBoards\PriceBoardResolver;
use App\Services\PriceBoards\PriceBoardV2Service;
use App\Support\HolidayPrices;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\PriceBoardSheet;
use Modules\Product\App\Models\Product;

/**
 * BẢNG GIÁ v2 (giai đoạn 1) của đối tác — một bảng gồm NHIỀU BỘ GIÁ (sheets), áp cho phòng / chi nhánh /
 * toàn bộ đối tác (kể cả phòng tạo sau). Cùng logic với trang Filament "Bảng giá"
 * (App\Services\PriceBoards\PriceBoardV2Service); ghi giá/khôi phục/job đêm vẫn là PriceBoardSyncService
 * như v1 nên bảng và app đang chạy không đổi.
 *
 *  - GET    /api/admin/price-boards                     danh sách (lọc, phân trang tuỳ chọn)
 *  - GET    /api/admin/price-boards/{id}                chi tiết + sheets[]
 *  - POST   /api/admin/price-boards                     tạo (body v2: scope + sheets[]; body v1: branch_id + items[]/product_ids[])
 *  - PUT|PATCH /api/admin/price-boards/{id}             sửa, bật/tắt
 *  - DELETE /api/admin/price-boards/{id}                xoá, phòng được tính lại giá
 *  - POST   /api/admin/price-boards/{id}/apply          áp ngay (tuỳ chọn giới hạn chi nhánh/phòng)
 *  - GET    /api/admin/price-boards/{id}/rooms          phòng nào nhận bộ giá nào
 *  - POST   /api/admin/price-boards/preview             xem trước body chưa lưu
 *  - POST   /api/admin/price-boards/{id}/duplicate      nhân bản
 *  - GET    /api/admin/price-boards/presets[/{code}]    bảng giá dựng sẵn
 *  - POST   /api/admin/price-boards/presets/{code}/use  tạo bảng thật từ bảng dựng sẵn
 *
 * Super_admin gửi X-Partner-Id để thao tác hộ một đối tác. days_of_week / priority (giai đoạn 2) trả 422.
 */
class PriceBoardController extends Controller
{
    public function __construct(
        private PriceBoardSyncService $sync,
        private PriceBoardV2Service $v2,
        private PriceBoardResolver $resolver,
    ) {
    }

    // ════════════════════════════════════════════════════════════════════
    // Danh sách / chi tiết
    // ════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'kind'       => ['nullable', Rule::in(['board', 'template'])],
            'branch_id'  => 'nullable|integer',
            'product_id' => 'nullable|string',
            'scope'      => ['nullable', Rule::in([PriceBoard::SCOPE_ROOMS, PriceBoard::SCOPE_BRANCHES, PriceBoard::SCOPE_PARTNER])],
            'status'     => ['nullable', Rule::in(['active', 'pending', 'expired', 'inactive'])],
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        // kind=template: danh sách bảng giá MẪU (price_templates) — cùng dữ liệu và định dạng với GET /price-templates, thêm kind.
        if ($request->input('kind') === 'template') {
            $response = app(PriceTemplateController::class)->index($request)->getData(true);
            $rows     = array_map(fn (array $t) => ['kind' => 'template'] + $t, $response['data'] ?? []);

            return response()->json(['data' => $rows, 'meta' => ['kind' => 'template', 'total' => count($rows), 'phase' => PriceBoard::phase2Enabled() ? 2 : 1]]);
        }

        $query = PriceBoard::visibleTo($user)->with(['branch:id,name', 'scopeBranches:id,name'])->withCount(['items', 'sheets']);

        if ($request->filled('scope')) {
            $query->where('scope', $request->input('scope'));
        }

        if ($request->filled('branch_id')) {
            // Bảng "bao" chi nhánh này: toàn đối tác, hoặc có chi nhánh trong danh sách, hoặc khoá theo chi
            // nhánh (v1), hoặc có phòng thuộc chi nhánh.
            $branchId   = (int) $request->input('branch_id');
            $productIds = Product::query()
                ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds($branchId)))
                ->pluck('id');

            $query->where(function ($q) use ($branchId, $productIds) {
                $q->where('scope', PriceBoard::SCOPE_PARTNER)
                    ->orWhere('category_id', $branchId)
                    ->orWhereHas('scopeBranches', fn ($b) => $b->where('categories.id', $branchId))
                    ->orWhereHas('items', fn ($i) => $i->whereIn('product_id', $productIds));
            });
        }

        if ($request->filled('product_id')) {
            $query->whereHas('items', fn ($i) => $i->where('product_id', (string) $request->input('product_id')));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $boards = $query->orderByDesc('id')->get();
        $this->pendingUpdates = app(\App\Services\ApprovalService::class)->pendingUpdateMap(\App\Models\ApprovalRequest::SUBJECT_PRICE_BOARD, $boards->pluck('id')->all());

        $all = $boards
            ->map(fn (PriceBoard $b) => $this->presentBoard($b))
            ->when($request->filled('status'), fn ($c) => $c->where('status', $request->input('status')))
            ->values();

        // Chỉ phân trang khi app yêu cầu (page / per_page) — app v1 không gửi nên vẫn nhận đủ danh sách.
        $paginate = $request->has('page') || $request->has('per_page');
        $perPage  = $paginate ? (int) $request->input('per_page', 20) : max(1, $all->count());
        $page     = $paginate ? (int) $request->input('page', 1) : 1;

        return response()->json([
            'data' => $paginate ? $all->forPage($page, $perPage)->values() : $all,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($all->count() / $perPage)),
                'per_page'     => $perPage,
                'total'        => $all->count(),
                'phase'        => PriceBoard::phase2Enabled() ? 2 : 1,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        return response()->json(['data' => $this->presentBoard($board, true)]);
    }

    // ════════════════════════════════════════════════════════════════════
    // Tạo / sửa / xoá
    // ════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate($this->rules(creating: true));

        $isV1 = ! array_key_exists('scope', $data) && ! array_key_exists('sheets', $data);

        if ($isV1 && empty($data['branch_id'])) {
            return $this->unprocessable('Thiếu dữ liệu.', ['branch_id' => ['Chi nhánh (branch_id) là bắt buộc.']]);
        }

        if (! $isV1 && empty($data['sheets'])) {
            return $this->unprocessable('Thiếu dữ liệu.', ['sheets' => ['Bảng giá cần ít nhất 1 bộ giá.']]);
        }

        return $this->save($user, null, $data, creating: true);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $board = $this->find($user, $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate($this->rules(creating: false));

        return $this->save($user, $board, $data, creating: false);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        // Phải chụp danh sách phòng TRƯỚC khi xoá — xoá bảng là cascade mất luôn các item.
        $affected = $this->sync->productsAffectedByBoard($board);

        DB::transaction(function () use ($board, $affected) {
            $board->delete();
            $this->sync->resyncProductsAfterBoardDeleted($affected);
        });

        return response()->json(['message' => 'Đã xoá bảng giá.']);
    }

    /** POST /{id}/apply — áp ngay xuống phòng, bất kể ngày hiệu lực; body tuỳ chọn giới hạn chi nhánh/phòng. */
    public function apply(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $board->isApproved()) {
            return response()->json(['message' => 'Bảng giá chưa được 365home duyệt nên chưa áp dụng được.'], 422);
        }

        $data = $request->validate([
            'branch_ids'    => 'nullable|array',
            'branch_ids.*'  => 'integer',
            'product_ids'   => 'nullable|array',
            'product_ids.*' => 'string',
        ]);

        $filter = null;

        if (! empty($data['product_ids']) || ! empty($data['branch_ids'])) {
            $filter = collect($data['product_ids'] ?? []);

            if (! empty($data['branch_ids'])) {
                $categoryIds = collect($data['branch_ids'])->flatMap(fn ($b) => PriceBoard::branchCategoryIds((int) $b))->unique()->all();
                $filter      = $filter->merge(
                    Product::query()->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))->pluck('id')
                );
            }

            $filter = $filter->map(fn ($v) => (string) $v)->unique()->values();
        }

        $inBoard = $board->items()->pluck('product_id')->map(fn ($v) => (string) $v);
        $targets = $filter === null ? $inBoard : $inBoard->intersect($filter)->values();
        $applied = $this->sync->applyBoardTo($board, $targets->all());

        $skipped = collect($filter ?? [])
            ->diff($inBoard)
            ->map(fn ($id) => ['product_id' => (string) $id, 'reason' => 'Phòng không khớp bộ giá nào'])
            ->values();

        return response()->json([
            'message' => (PriceBoard::phase2Enabled() ? 'Đã bật bảng giá từ hôm nay cho ' : 'Đã áp dụng bảng giá cho ') . count($applied) . ' phòng.',
            'applied' => count($applied),
            'skipped' => $skipped,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════
    // Phòng nhận giá / xem trước / nhân bản
    // ════════════════════════════════════════════════════════════════════

    /** GET /{id}/rooms — phòng nào nhận bộ giá nào, đang áp hay bị bảng khác chặn. */
    public function rooms(Request $request, int $id): JsonResponse
    {
        $board = $this->find($request->user(), $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        $request->validate([
            'branch_id' => 'nullable|integer',
            'sheet_id'  => 'nullable|integer',
            'status'    => ['nullable', Rule::in(['applied', 'waiting', 'blocked', 'overridden', 'unmatched'])],
            'page'      => 'nullable|integer|min:1',
            'per_page'  => 'nullable|integer|min:1|max:100',
        ]);

        $spec    = $this->v2->specFromBoard($board);
        $eval    = $this->v2->evaluate($spec, $board);

        return $this->roomsResponse($request, $spec, $eval, $board);
    }

    /** POST /preview — như /rooms nhưng cho body chưa lưu (hoặc 1 lần sửa: gửi kèm "id"). Không ghi gì. */
    public function preview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate($this->rules(creating: false) + ['id' => 'nullable|integer']);

        $board = null;

        if (! empty($data['id'])) {
            $board = $this->find($user, (int) $data['id']);

            if (! $board) {
                return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
            }
        }

        try {
            $spec = $this->v2->specFromRequest($data, (string) ($board?->partner_id ?? $user->partner_id), $user, $board, dryRun: true);
        } catch (ValidationException $e) {
            return $this->unprocessable('Dữ liệu không hợp lệ.', $e->errors());
        }

        $eval = $this->v2->evaluate($spec, $board);

        if ($failure = $this->failureResponse($eval)) {
            return $failure;
        }

        return $this->roomsResponse($request, $spec, $eval, $board);
    }

    /** POST /{id}/duplicate — nhân bản bảng (copy đủ phạm vi + bộ giá), mặc định tắt. */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user  = $request->user();
        $board = $this->find($user, $id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate([
            'name'       => 'nullable|string|max:255',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'is_active'  => 'nullable|boolean',
        ]);

        $data['name'] ??= $board->name . ' (bản sao)';

        $clone = $this->v2->cloneBoard($board, (string) $board->partner_id, $data);

        return $this->persistSpec($clone['spec'], null, 201, 'Đã nhân bản bảng giá.');
    }

    // ════════════════════════════════════════════════════════════════════
    // Bảng giá dựng sẵn (preset)
    // ════════════════════════════════════════════════════════════════════

    /**
     * GET /price-boards/options?branch_id=&room_style= — bảng giá + bộ giá có thể chọn khi TẠO / SỬA PHÒNG
     * (POST|PUT /products kèm price_board_id [+ price_board_sheet_id]). Chỉ bộ kiểu thay thế giá, đúng loại phòng.
     */
    public function roomOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $data = $request->validate([
            'branch_id'  => 'nullable|integer',
            'room_style' => ['nullable', Rule::in([1, 2])],
            'kind'       => ['nullable', Rule::in(['all', 'board', 'template'])],
        ]);

        $kind  = $data['kind'] ?? 'all';
        $style = isset($data['room_style']) ? (int) $data['room_style'] : null;

        $boards = $kind === 'template' ? collect() : app(\App\Services\PriceBoards\RoomPriceBoardPicker::class)->options(
            (string) $user->partner_id,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $style,
        );

        // Bảng giá MẪU (price_templates) chỉ gồm khung giờ + giá nên chỉ dùng cho phòng theo giờ.
        $templates = ($kind === 'board' || $style === 2) ? [] : $this->templateOptionItems($request);

        $boardItems = $boards->map(fn (PriceBoard $b) => [
            'kind'       => 'board',
            'use'        => ['field' => 'price_board_id', 'id' => $b->id, 'sheet_field' => 'price_board_sheet_id'],
            'id'         => $b->id,
            'name'       => $b->name,
            'scope'      => $b->scope ?: PriceBoard::SCOPE_ROOMS,
            'is_active'  => (bool) $b->is_active,
            'status'     => $this->boardStatus($b),
            'start_date' => $b->start_date?->toDateString(),
            'end_date'   => $b->end_date?->toDateString(),
            'priority'   => (int) $b->priority,
            'days_of_week' => array_values(array_map('intval', (array) $b->days_of_week)),
            'branches'   => $b->scopeBranches->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'sheets'     => $b->sheets->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'room_style' => (int) $s->room_style,
                'is_default' => (bool) $s->is_default,
                'time_slots' => $s->slots->map(fn ($sl) => [
                    'timeslot_id' => (int) $sl->timeslot_id,
                    'label'       => $sl->timeslot?->label,
                    'start_time'  => $sl->timeslot?->start_time,
                    'end_time'    => $sl->timeslot?->end_time,
                    'price'       => (int) $sl->price,
                    'over_night'  => (bool) $sl->over_night,
                ])->values(),
                'price'            => $s->price === null ? null : (int) $s->price,
                'weekday_prices'   => $s->weekday_prices ?: null,
                'holiday_prices'   => $s->holiday_prices ?: null,
                'weekday_adjustments' => $s->weekday_adjustments ?: null,
                'default_checkin'  => $s->default_checkin,
                'default_checkout' => $s->default_checkout,
            ])->values(),
        ])->values()->all();

        return response()->json(['data' => array_merge($boardItems, $templates)]);
    }

    /**
     * Bảng giá MẪU (price_templates) trình bày cùng dạng với bảng giá trong /options (kind = template): một bộ giá duy nhất
     * "Khung giờ + giá". Dùng khi tạo phòng bằng `price_template_id` — id là id của bảng giá mẫu, KHÔNG đổi so với API /price-templates.
     *
     * @return list<array<string,mixed>>
     */
    private function templateOptionItems(Request $request): array
    {
        $request->query->set('is_active', '1');
        $items = app(PriceTemplateController::class)->index($request)->getData(true)['data'] ?? [];

        return array_map(fn (array $t) => [
            'kind'         => 'template',
            'use'          => ['field' => 'price_template_id', 'id' => $t['id'], 'sheet_field' => null],
            'id'           => $t['id'],
            'name'         => $t['name'],
            'scope'        => null,
            'is_active'    => true,
            'status'       => 'template',
            'is_system'    => $t['is_system'],
            'start_date'   => null,
            'end_date'     => null,
            'priority'     => 0,
            'days_of_week' => [],
            'branches'     => [],
            'sheets'       => [[
                'id'         => null,
                'name'       => 'Khung giờ + giá',
                'room_style' => 1,
                'is_default' => true,
                'time_slots' => array_map(fn (array $s) => [
                    'timeslot_id' => (int) $s['timeslot_id'],
                    'label'       => $s['time_slot']['label'] ?? null,
                    'start_time'  => $s['time_slot']['start_time'] ?? null,
                    'end_time'    => $s['time_slot']['end_time'] ?? null,
                    'price'       => (int) $s['price'],
                    'over_night'  => (bool) $s['over_night'],
                ], $t['slots']),
                'price'            => null,
                'default_checkin'  => null,
                'default_checkout' => null,
            ]],
        ], $items);
    }

    public function presets(Request $request): JsonResponse
    {
        $partnerId = $this->partnerIdFor($request->user());

        $data = $this->presetQuery()->get()->map(function (PriceBoard $p) use ($partnerId) {
            $detail = $this->v2->presetDetail($p, $partnerId);
            unset($detail['sheets']);

            return $detail;
        })->values();

        return response()->json(['data' => $data, 'meta' => ['phase' => PriceBoard::phase2Enabled() ? 2 : 1]]);
    }

    public function presetShow(Request $request, string $code): JsonResponse
    {
        $preset = $this->presetQuery()->where('code', $code)->first();

        if (! $preset) {
            return response()->json(['message' => 'Không tìm thấy bảng giá dựng sẵn.'], 404);
        }

        return response()->json(['data' => $this->v2->presetDetail($preset, $this->partnerIdFor($request->user()))]);
    }

    /** POST /presets/{code}/use — tạo bảng thật của đối tác từ preset (copy 1 lần, mặc định tắt). */
    public function presetUse(Request $request, string $code): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($error = $this->partnerError($user)) {
            return $error;
        }

        $preset = $this->presetQuery()->where('code', $code)->first();

        if (! $preset) {
            return response()->json(['message' => 'Không tìm thấy bảng giá dựng sẵn.'], 404);
        }

        $data = $request->validate(array_merge($this->rules(creating: false), ['name' => 'nullable|string|max:255']));

        foreach (['days_of_week', 'priority'] as $field) {
            if (! PriceBoard::phase2Enabled() && ! empty($data[$field])) {
                return $this->unprocessable('Dữ liệu không hợp lệ.', [$field => ['Chưa hỗ trợ ở giai đoạn này.']]);
            }
        }

        if ($preset->requires_dates && (empty($data['start_date']) || empty($data['end_date']))) {
            return $this->unprocessable('Dữ liệu không hợp lệ.', ['start_date' => ['Bảng dựng sẵn này bắt buộc chọn ngày bắt đầu và kết thúc.']]);
        }

        $partnerId = (string) $user->partner_id;

        $data['name'] ??= $preset->name . ($code === 'holiday' ? ' ' . now()->year : '');
        $scope = $data['scope'] ?? ($preset->default_scope ?: PriceBoard::SCOPE_PARTNER);

        // Chi nhánh / phòng chọn phải thuộc đối tác (cùng luật khi tạo bảng thường).
        $check = $this->v2->specFromRequest(
            ['scope' => $scope, 'branch_ids' => $data['branch_ids'] ?? [], 'product_ids' => $data['product_ids'] ?? []],
            $partnerId,
            $user,
        );

        $clone = $this->v2->cloneBoard($preset, $partnerId, [
            'name'        => $data['name'],
            'note'        => $data['note'] ?? null,
            'start_date'  => $data['start_date'] ?? null,
            'end_date'    => $data['end_date'] ?? null,
            'is_active'   => $data['is_active'] ?? false,
            'scope'       => $check['scope'],
            'branch_ids'  => $check['branch_ids'],
            'product_ids' => $check['product_ids'],
            'source_preset' => $code,
            'priority'      => $data['priority'] ?? null,
            'days_of_week'  => $data['days_of_week'] ?? null,
        ], resolvePresetTimes: true);

        $spec = $clone['spec'];

        if (! empty($data['sheets'])) {
            try {
                $spec = $this->v2->applySheetOverrides($spec, $data['sheets'], $partnerId);
            } catch (ValidationException $e) {
                return $this->unprocessable('Dữ liệu không hợp lệ.', $e->errors());
            }
        }

        $response = $this->persistSpec($spec, null, 201, 'Đã tạo bảng giá từ bảng dựng sẵn.');

        if ($response->getStatusCode() === 201) {
            $payload = $response->getData(true);
            $payload['created_time_slots'] = $clone['created_time_slots'];

            return response()->json($payload, 201);
        }

        return $response;
    }

    // ════════════════════════════════════════════════════════════════════
    // Nội bộ
    // ════════════════════════════════════════════════════════════════════

    private function save(User $user, ?PriceBoard $board, array $data, bool $creating): JsonResponse
    {
        try {
            $spec = $this->v2->specFromRequest($data, (string) ($board?->partner_id ?? $user->partner_id), $user, $board);
        } catch (ValidationException $e) {
            return $this->unprocessable('Dữ liệu không hợp lệ.', $e->errors());
        }

        return $this->persistSpec($spec, $board, $creating ? 201 : 200, $creating ? 'Đã tạo bảng giá.' : 'Đã cập nhật bảng giá.');
    }

    private function persistSpec(array $spec, ?PriceBoard $board, int $status, string $message): JsonResponse
    {
        $eval = $this->v2->evaluate($spec, $board);

        if ($failure = $this->failureResponse($eval)) {
            return $failure;
        }

        $saved = $this->v2->persist($spec, $board);

        if (\App\Support\ApprovalContext::hasNotices()) {
            $message = $board === null ? 'Đã tạo bảng giá, đang chờ 365home duyệt.' : 'Đã gửi bản sửa bảng giá cho 365home duyệt — bảng đang chạy giữ nguyên cho tới khi được duyệt.';
        }

        return response()->json([
            'message' => $message,
            'data'    => $this->presentBoard($saved, true),
            'summary' => $this->v2->summary($this->v2->specFromBoard($saved), $this->resolver->resolve($this->v2->specFromBoard($saved))),
        ], $status);
    }

    /** @param array<string,mixed> $eval */
    private function failureResponse(array $eval): ?JsonResponse
    {
        if ($eval['errors']) {
            return $this->unprocessable('Dữ liệu không hợp lệ.', $eval['errors']);
        }

        if ($eval['conflicts']) {
            $first = $eval['conflicts'][0];
            $count = collect($eval['conflicts'])->pluck('product_ids')->flatten()->unique()->count();

            return response()->json([
                'message'   => "{$count} phòng đang nằm trong bảng \"{$first['board']['name']}\" đang bật và trùng ngày.",
                'errors'    => ['is_active' => ['Tắt bảng kia hoặc đổi khoảng ngày.']],
                'conflicts' => $eval['conflicts'],
            ], 422);
        }

        return null;
    }

    /** @param array<string,list<string>> $errors */
    private function unprocessable(string $message, array $errors): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => $errors], 422);
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? ['sometimes', 'required'] : ['sometimes'];

        return [
            'name'             => array_merge($req, ['string', 'max:255']),
            'note'             => 'nullable|string',
            'scope'            => ['nullable', Rule::in([PriceBoard::SCOPE_ROOMS, PriceBoard::SCOPE_BRANCHES, PriceBoard::SCOPE_PARTNER])],
            'branch_id'        => 'nullable|integer',
            'branch_ids'       => 'nullable|array',
            'branch_ids.*'     => 'integer',
            'product_ids'      => 'nullable|array',
            'product_ids.*'    => 'string',
            'is_active'        => 'nullable|boolean',
            'start_date'       => 'nullable|date_format:Y-m-d',
            'end_date'         => 'nullable|date_format:Y-m-d|after_or_equal:start_date',

            // giai đoạn 2 — nhận để trả 422 rõ ràng
            'days_of_week'     => 'nullable|array',
            'priority'         => 'nullable|integer',

            // ── v1 ──────────────────────────────────────────────────────────
            'pricing_mode'     => ['nullable', Rule::in([PriceBoard::MODE_OVERRIDE, PriceBoard::MODE_ADJUSTMENT])],
            'adjustment_type'  => ['nullable', Rule::in(['percentage', 'fixed'])],
            'adjustment_value' => 'nullable|numeric',
            'items'            => 'nullable|array',
            'items.*.product_id'                      => 'required_with:items|string',
            'items.*.full_booking_discount'           => 'nullable|string|max:50',
            'items.*.bulk_discount_rules'             => 'nullable|array',
            'items.*.bulk_discount_rules.*.slots'     => 'required|integer|min:1',
            'items.*.bulk_discount_rules.*.discount'  => 'required|numeric|min:0|max:100',
            'items.*.room_config'                     => 'nullable|array',
            'items.*.room_config.max_free_guests'     => 'nullable|integer|min:0',
            'items.*.room_config.extra_guest_fee'     => 'nullable|numeric|min:0',
            'items.*.price'                           => 'nullable|numeric|min:0',
            'items.*.default_checkin'                 => 'nullable|date_format:H:i',
            'items.*.default_checkout'                => 'nullable|date_format:H:i',
            'items.*.deposit_min_nights'              => 'nullable|integer|min:1',
            'items.*.deposit_multi_night'             => 'nullable|integer|min:0|max:100',
            'items.*.time_slots'                      => 'nullable|array',
            'items.*.time_slots.*.timeslot_id'        => 'required|integer',
            'items.*.time_slots.*.price'              => 'required|integer|min:0',
            'items.*.time_slots.*.over_night'         => 'nullable|boolean',
            'items.*.time_slots.*.status'             => 'nullable|string|max:30',

            // ── v2 ──────────────────────────────────────────────────────────
            'sheets'                                  => 'nullable|array',
            'sheets.*.id'                             => 'nullable|integer',
            'sheets.*.name'                           => 'nullable|string|max:255',
            'sheets.*.pricing_mode'                   => ['nullable', Rule::in([PriceBoard::MODE_OVERRIDE, PriceBoard::MODE_ADJUSTMENT])],
            'sheets.*.room_style'                     => 'nullable|integer|in:1,2',
            'sheets.*.is_default'                     => 'nullable|boolean',
            'sheets.*.sort_order'                     => 'nullable|integer',
            'sheets.*.product_ids'                    => 'nullable|array',
            'sheets.*.product_ids.*'                  => 'string',
            'sheets.*.time_slots'                     => 'nullable|array',
            'sheets.*.time_slots.*.timeslot_id'       => 'nullable|integer',
            'sheets.*.time_slots.*.start_time'        => 'nullable|date_format:H:i',
            'sheets.*.time_slots.*.end_time'          => 'nullable|date_format:H:i',
            'sheets.*.time_slots.*.price'             => 'nullable|integer|min:0',
            'sheets.*.time_slots.*.over_night'        => 'nullable|boolean',
            'sheets.*.time_slots.*.status'            => 'nullable|string|max:30',
            'sheets.*.price'                          => 'nullable|numeric|min:0',
            'sheets.*.weekday_prices'                 => 'nullable|array',
            'sheets.*.weekday_prices.*'               => 'nullable|integer|min:0|max:999999999',
            // Giá ngày lễ: cấp bộ giá (sheets.*.holiday_prices) và dùng chung cả bảng (holiday_prices) — from/to "MM-DD" khi recurring (lặp hàng năm)
            // hoặc "YYYY-MM-DD"; hoặc chỉ gửi holiday_id (lấy khoảng ngày từ danh mục ngày lễ). Service kiểm tra chi tiết.
            ...$this->holidayRules('sheets.*.holiday_prices'),
            ...$this->holidayRules('holiday_prices'),
            // Tăng/giảm theo thứ (không dùng cùng weekday_prices): days = thứ ISO 1 (Thứ 2) … 7 (Chủ nhật).
            'sheets.*.weekday_adjustments'            => 'nullable|array|max:' . \App\Support\WeekdayAdjustments::MAX_ROWS,
            'sheets.*.weekday_adjustments.*'          => 'array',
            'sheets.*.weekday_adjustments.*.days'     => 'required|array',
            'sheets.*.weekday_adjustments.*.days.*'   => 'integer|min:1|max:7',
            'sheets.*.weekday_adjustments.*.mode'     => ['required', Rule::in(\App\Support\WeekdayAdjustments::MODES)],
            'sheets.*.weekday_adjustments.*.value'    => 'required|numeric',
            'sheets.*.default_checkin'                => 'nullable|date_format:H:i',
            'sheets.*.default_checkout'               => 'nullable|date_format:H:i',
            'sheets.*.deposit_min_nights'             => 'nullable|integer|min:1',
            'sheets.*.deposit_multi_night'            => 'nullable|integer|min:0|max:100',
            'sheets.*.full_booking_discount'          => 'nullable|string|max:50',
            'sheets.*.full_day_price'                 => 'nullable|integer|min:0|max:999999999',
            'sheets.*.bulk_discount_rules'            => 'nullable|array',
            'sheets.*.bulk_discount_rules.*.slots'    => 'required|integer|min:1',
            'sheets.*.bulk_discount_rules.*.discount' => 'required|numeric|min:0|max:100',
            'sheets.*.room_config'                    => 'nullable|array',
            'sheets.*.room_config.max_free_guests'    => 'nullable|integer|min:0',
            'sheets.*.room_config.extra_guest_fee'    => 'nullable|numeric|min:0',
            'sheets.*.adjustment_type'                => ['nullable', Rule::in(['percentage', 'fixed'])],
            'sheets.*.adjustment_value'               => 'nullable|numeric',
            'sheets.*.adjustment_timeslot_ids'        => 'nullable|array',
            'sheets.*.adjustment_timeslot_ids.*'      => 'integer',
        ];
    }

    /** @return array<string,mixed> */
    private function holidayRules(string $key): array
    {
        return [
            $key                  => 'nullable|array|max:' . HolidayPrices::MAX_ROWS,
            "{$key}.*"            => 'array',
            "{$key}.*.holiday_id" => 'nullable|integer',
            "{$key}.*.name"       => 'nullable|string|max:100',
            "{$key}.*.recurring"  => 'nullable|boolean',
            "{$key}.*.from"       => "required_without:{$key}.*.holiday_id|nullable|string|max:10",
            "{$key}.*.to"         => "required_without:{$key}.*.holiday_id|nullable|string|max:10",
            // mode: price (giá cố định, mặc định — gửi price hoặc value) | percent (tăng/giảm %) | fixed (cộng/trừ số tiền)
            "{$key}.*.mode"       => ['nullable', Rule::in(HolidayPrices::MODES)],
            "{$key}.*.price"      => 'nullable|numeric',
            "{$key}.*.value"      => 'nullable|numeric',
        ];
    }

    private function find(User $user, int $id): ?PriceBoard
    {
        return PriceBoard::visibleTo($user)->with(['branch:id,name', 'scopeBranches:id,name'])->find($id);
    }

    private function presetQuery()
    {
        return PriceBoard::query()->where('is_template', true)->whereNull('partner_id')
            ->when(! PriceBoard::phase2Enabled(), fn ($q) => $q->where('phase', '<=', 1))
            ->orderBy('sort_order')->orderBy('id');
    }

    /** Đối tác dùng để dò khung giờ của preset (super_admin chưa chọn đối tác → null = chỉ khung hệ thống). */
    private function partnerIdFor(User $user): ?string
    {
        return $user->seesAllPartners() ? null : ($user->partner_id ? (string) $user->partner_id : null);
    }

    /** Tài khoản đối tác dùng đối tác của mình; super_admin phải chọn đối tác (X-Partner-Id). */
    private function partnerError(User $user): ?JsonResponse
    {
        if ($user->seesAllPartners()) {
            return response()->json(['message' => 'Chọn đối tác (header X-Partner-Id) để tạo/sửa bảng giá cho đối tác đó.'], 422);
        }

        if (empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản chưa thuộc đối tác nào.'], 403);
        }

        return null;
    }

    // ════════════════════════════════════════════════════════════════════
    // Trình bày response
    // ════════════════════════════════════════════════════════════════════

    private function boardStatus(PriceBoard $board): string
    {
        $today = now()->startOfDay();

        return match (true) {
            ! $board->is_active                                   => 'inactive',
            $board->coversDate($today)                            => 'active',
            $board->start_date && $today->lt($board->start_date)  => 'pending',
            default                                               => 'expired',
        };
    }

    /** @var array<string, int> bảng giá (id) => id yêu cầu sửa đang chờ duyệt — nạp 1 lần ở index(), show() tự tra. */
    private array $pendingUpdates = [];

    private function presentBoard(PriceBoard $board, bool $detail = false): array
    {
        if ($detail && ! isset($this->pendingUpdates[(string) $board->id])) {
            $this->pendingUpdates += app(\App\Services\ApprovalService::class)->pendingUpdateMap(\App\Models\ApprovalRequest::SUBJECT_PRICE_BOARD, [$board->id]);
        }

        $branches = $board->relationLoaded('scopeBranches') ? $board->scopeBranches : $board->scopeBranches()->get();

        $out = [
            'id'               => $board->id,
            'name'             => $board->name,
            'note'             => $board->note,
            'partner_id'       => $board->partner_id,
            'scope'            => $board->scope ?: PriceBoard::SCOPE_ROOMS,
            'branch_ids'       => $branches->pluck('id')->map(fn ($v) => (int) $v)->values(),
            'branches'         => $branches->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            'branch_id'        => $board->category_id,
            'branch'           => $board->branch ? ['id' => $board->branch->id, 'name' => $board->branch->name] : null,
            'start_date'       => $board->start_date?->toDateString(),
            'end_date'         => $board->end_date?->toDateString(),
            'is_active'        => (bool) $board->is_active,
            'status'           => $this->boardStatus($board),
            'pricing_mode'     => $board->pricing_mode,
            'adjustment_type'  => $board->adjustment_type,
            'adjustment_value' => $board->adjustment_value === null ? null : (float) $board->adjustment_value,
            'source_preset'    => $board->source_preset,
            'priority'         => (int) $board->priority,
            'days_of_week'     => array_values(array_map('intval', (array) $board->days_of_week)),
            'holiday_prices'   => $board->holiday_prices ?: null,
            'sheets_count'     => $board->sheets_count ?? $board->sheets()->count(),
            'rooms_count'      => $board->items_count ?? $board->items()->count(),
            // Duyệt trước khi đăng: approved | pending (đăng mới, chờ 365home duyệt) | rejected. pending_update_id = id yêu cầu sửa đang chờ (bảng vẫn chạy bản cũ).
            'approval_status'  => $board->approval_status ?? 'approved',
            'pending_update_id' => $this->pendingUpdates[(string) $board->id] ?? null,
        ];

        if (! $detail) {
            return $out;
        }

        $spec    = $this->v2->specFromBoard($board);
        $resolve = $this->resolver->resolve($spec);
        $out['rooms_count'] = count($resolve['assignments']);

        $labels = \Modules\Product\App\Models\TimeSlot::query()
            ->whereIn('id', collect($spec['sheets'])->flatMap(fn ($s) => collect($s['time_slots'])->pluck('timeslot_id'))->filter()->unique()->all() ?: [0])
            ->get()
            ->keyBy('id');

        $out['sheets'] = $board->sheets->values()->map(function (PriceBoardSheet $sheet, int $i) use ($resolve, $labels) {
            $rooms = $sheet->products->map(fn ($p) => $resolve['products']->get($p->product_id))->filter();

            return [
                'id'           => $sheet->id,
                'name'         => $sheet->name,
                'pricing_mode' => $sheet->pricing_mode,
                'room_style'   => $sheet->room_style,
                'is_default'   => (bool) $sheet->is_default,
                'sort_order'   => (int) $sheet->sort_order,
                'product_ids'  => $sheet->products->pluck('product_id')->values(),
                'rooms'        => $rooms->map(fn (Product $p) => [
                    'id' => $p->id, 'name' => $p->name,
                    ...$this->branchOf($p),
                ])->values(),
                'rooms_count'  => (int) ($resolve['per_sheet'][$i] ?? 0),
                'full_booking_discount' => $sheet->full_booking_discount,
                'full_day_price'        => $sheet->full_day_price === null ? null : (int) $sheet->full_day_price,
                'bulk_discount_rules'   => $sheet->bulk_discount_rules ?? [],
                'room_config'           => $sheet->room_config,
                'time_slots'   => $sheet->slots->map(fn ($s) => [
                    'timeslot_id' => (int) $s->timeslot_id,
                    'label'       => $labels->get($s->timeslot_id)?->label,
                    'start_time'  => $labels->get($s->timeslot_id)?->start_time,
                    'end_time'    => $labels->get($s->timeslot_id)?->end_time,
                    'price'       => $s->price === null ? null : (int) $s->price,
                    'over_night'  => (bool) $s->over_night,
                    'status'      => $s->status,
                ])->values(),
                'price'               => $sheet->price === null ? null : (float) $sheet->price,
                'weekday_prices'      => $sheet->weekday_prices ?: null,
                'holiday_prices'      => $sheet->holiday_prices ?: null,
                'weekday_adjustments' => $sheet->weekday_adjustments ?: null,
                'default_checkin'     => $sheet->default_checkin,
                'default_checkout'    => $sheet->default_checkout,
                'deposit_min_nights'  => $sheet->deposit_min_nights,
                'deposit_multi_night' => $sheet->deposit_multi_night,
                'adjustment_type'     => $sheet->adjustment_type,
                'adjustment_value'    => $sheet->adjustment_value === null ? null : (float) $sheet->adjustment_value,
                'adjustment_timeslot_ids' => array_map('intval', (array) $sheet->adjustment_timeslot_ids),
            ];
        })->values();

        // ── Tương thích v1: items[] (override) hoặc product_ids[] (adjustment) ──────────────────
        $items = $board->items()->with(['timeSlots.timeslot', 'product'])->get();

        if ($board->isAdjustment()) {
            $out['product_ids'] = $items->pluck('product_id')->values();
            $out['rooms']       = $items->map(fn (PriceBoardItem $i) => ['id' => $i->product_id, 'name' => $i->product?->name])->values();

            return $out;
        }

        $out['items'] = $items->map(fn (PriceBoardItem $i) => [
            'product_id'            => $i->product_id,
            'room_name'             => $i->product?->name,
            'styles'                => (int) ($i->product?->styles ?? 1),
            'price'                 => $i->price === null ? null : (float) $i->price,
            'weekday_prices'        => $i->weekday_prices ?: null,
            'holiday_prices'        => $i->holiday_prices ?: null,
            'weekday_adjustments'   => $i->weekday_adjustments ?: null,
            'full_booking_discount' => $i->full_booking_discount,
            'full_day_price'        => $i->full_day_price === null ? null : (int) $i->full_day_price,
            'bulk_discount_rules'   => $i->bulk_discount_rules ?? [],
            'room_config'           => $i->room_config,
            'default_checkin'       => $i->default_checkin,
            'default_checkout'      => $i->default_checkout,
            'deposit_min_nights'    => $i->deposit_min_nights,
            'deposit_multi_night'   => $i->deposit_multi_night,
            'time_slots'            => $i->timeSlots
                ->sortBy(fn ($s) => $s->timeslot?->start_time ?? '99:99:99')
                ->map(fn ($s) => [
                    'timeslot_id' => $s->timeslot_id,
                    'label'       => $s->timeslot?->label,
                    'start_time'  => $s->timeslot?->start_time,
                    'end_time'    => $s->timeslot?->end_time,
                    'price'       => $s->price === null ? null : (int) $s->price,
                    'over_night'  => (bool) $s->over_night,
                    'status'      => $s->status,
                ])->values(),
        ])->values();

        return $out;
    }

    /** @return array{branch_id: ?int, branch_name: ?string} chi nhánh gốc của phòng */
    private function branchOf(Product $product): array
    {
        $root = null;

        foreach ($product->categories as $category) {
            $current = $category;
            $guard   = 0;

            while ($current && $current->parent_id && $guard++ < 10) {
                $current = Category::withoutGlobalScopes()->find($current->parent_id);
            }

            if ($current) {
                $root = $current;
                break;
            }
        }

        return ['branch_id' => $root?->id, 'branch_name' => $root?->name];
    }

    /**
     * Danh sách phòng + trạng thái (dùng cho /rooms và /preview).
     *
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $eval
     */
    private function roomsResponse(Request $request, array $spec, array $eval, ?PriceBoard $board): JsonResponse
    {
        $resolve = $eval['resolve'];
        $sheets  = array_values($spec['sheets'] ?? []);
        $today   = Carbon::today();

        $coversToday = (! ($spec['start_date'] ?? null) || $today->gte(Carbon::parse($spec['start_date'])))
            && (! ($spec['end_date'] ?? null) || $today->lte(Carbon::parse($spec['end_date'])));

        // Phòng bị bảng khác chặn: tính như thể bảng này đang bật (đúng nghĩa "bật lên sẽ 422").
        $probe = $board ? clone $board : new PriceBoard();
        $probe->forceFill([
            'is_active'    => true,
            'scope'        => $spec['scope'] ?? PriceBoard::SCOPE_ROOMS,
            'priority'     => (int) ($spec['priority'] ?? 0),
            'days_of_week' => $spec['days_of_week'] ?? [],
            'holiday_prices' => $spec['holiday_prices'] ?? null,
            'start_date'   => $spec['start_date'] ?? null,
            'end_date'     => $spec['end_date'] ?? null,
        ]);

        $blockedBy = [];
        foreach ($this->sync->overlapConflicts($probe, collect(array_keys($resolve['assignments']))) as $conflict) {
            foreach ($conflict['product_ids'] as $pid) {
                $blockedBy[(string) $pid] ??= ['id' => $conflict['board']->id, 'name' => $conflict['board']->name];
            }
        }

        $removed = [];
        foreach ($eval['summary']['slot_conflicts']['items'] as $item) {
            $removed[$item['product_id']] = ($removed[$item['product_id']] ?? 0) + $item['future_orders'];
        }

        $rows = [];

        foreach ($resolve['products'] as $productId => $product) {
            $assign = $resolve['assignments'][$productId] ?? null;
            $sheet  = $assign ? ($sheets[$assign['sheet']] ?? null) : null;

            // Giai đoạn 2: bị bảng ưu tiên cao hơn thắng hôm nay.
            $winning = null;

            if ($assign && PriceBoard::phase2Enabled() && ! empty($spec['is_active']) && $coversToday
                && $probe->coversWeekday($today)) {
                $winner = $this->sync->winningItem((string) $productId, $today)?->priceBoard;

                if ($winner && (! $board || $winner->id !== $board->id)
                    && ((int) $winner->priority > (int) $probe->priority
                        || ((int) $winner->priority === (int) $probe->priority && $winner->scopeRank() > $probe->scopeRank()))) {
                    $winning = ['id' => $winner->id, 'name' => $winner->name, 'priority' => (int) $winner->priority];
                }
            }

            $status = match (true) {
                $assign === null                                             => 'unmatched',
                $winning !== null                                            => 'overridden',
                isset($blockedBy[(string) $productId])                       => 'blocked',
                ! empty($spec['is_active']) && $coversToday                  => 'applied',
                default                                                      => 'waiting',
            };

            $rows[] = [
                'product_id' => (string) $productId,
                'room_name'  => $product->name,
                'styles'     => (int) ($product->styles ?? 1),
                'branch'     => (function () use ($product) {
                    $b = $this->branchOf($product);

                    return $b['branch_id'] ? ['id' => $b['branch_id'], 'name' => $b['branch_name']] : null;
                })(),
                'sheet'      => $sheet ? ['id' => $sheet['id'] ?? null, 'name' => $sheet['name']] : null,
                'matched_by' => $assign['matched_by'] ?? null,
                'status'     => $status,
                'blocking_board' => $blockedBy[(string) $productId] ?? null,
                'winning_board'  => $winning,
                'future_orders_on_removed_slots' => (int) ($removed[(string) $productId] ?? 0),
            ];
        }

        $rows = collect($rows)
            ->when($request->filled('branch_id'), fn ($c) => $c->filter(fn ($r) => ($r['branch']['id'] ?? null) === (int) $request->input('branch_id')))
            ->when($request->filled('sheet_id'), fn ($c) => $c->filter(fn ($r) => ($r['sheet']['id'] ?? null) === (int) $request->input('sheet_id')))
            ->when($request->filled('status'), fn ($c) => $c->filter(fn ($r) => $r['status'] === $request->input('status')))
            ->sortBy('room_name')
            ->values();

        $perPage = (int) $request->input('per_page', 20);
        $page    = (int) $request->input('page', 1);

        return response()->json([
            'data'    => $rows->forPage($page, $perPage)->values(),
            'meta'    => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($rows->count() / $perPage)),
                'per_page'     => $perPage,
                'total'        => $rows->count(),
            ],
            'summary' => $eval['summary'],
        ]);
    }
}
