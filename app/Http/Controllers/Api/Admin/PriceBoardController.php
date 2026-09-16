<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardPriceLog;
use Modules\Product\App\Models\Product;

/**
 * CRUD + vận hành "Bảng giá" đặt tên (price_boards/price_board_items) cho panel Home — bản API của
 * đúng tính năng đã có ở Filament (Modules\Book\App\Filament\Resources\PriceBoardResource). Không tạo
 * logic ghi giá mới: mọi thao tác ghi đều gọi thẳng App\Services\PriceBoardSyncService, y hệt UI, để
 * 2 đường (Filament + API) không bao giờ lệch nhau.
 *
 * Quyền: CHỈ super_admin hoặc user có quyền Shield riêng của resource này
 * (view_any_price::board/create_price::board/update_price::board/delete_price::board — xem
 * PriceBoardResource::canViewAny()/canCreate()/canEdit()/canDelete()) — đây là công cụ đổi giá hàng
 * loạt, ghi thẳng xuống hệ thống ngay khi gọi, cùng mức rủi ro với "Sửa giá hàng loạt"
 * (BulkPriceController) nên áp đúng 1 chuẩn quyền.
 *
 * price_boards KHÔNG có cột partner_id (bảng toàn cục) — quyền truy cập theo TỪNG PHÒNG gắn trong
 * bảng giá (product_ids/items[].product_id) vẫn phải qua đúng partner_id + allowedCategoryIds() của
 * user, giống RoomPricingController — tránh 1 đối tác (nếu sau này được cấp quyền Shield) đổi giá
 * phòng của đối tác khác qua bảng giá.
 */
class PriceBoardController extends Controller
{
    /**
     * GET /api/admin/price-boards?search=&is_active=&pricing_mode=&per_page=
     * Danh sách bảng giá ĐẶT TÊN (không trả "Bảng giá mặc định", is_default=true — dữ liệu nội bộ,
     * xem PriceBoardResource::getEloquentQuery()).
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), 'view_any_price::board')) {
            return $this->forbidden();
        }

        $query = PriceBoard::query()->where('is_default', false)->withCount('items');

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($mode = $request->string('pricing_mode')->toString()) {
            $query->where('pricing_mode', $mode);
        }

        $perPage = (int) $request->integer('per_page', 20);
        $boards  = $query->orderByDesc('created_at')->paginate($perPage > 0 ? min($perPage, 100) : 20);

        $boards->getCollection()->transform(fn (PriceBoard $board) => $this->toListItem($board));

        return response()->json($boards);
    }

    /**
     * GET /api/admin/price-boards/{id}
     * Chi tiết 1 bảng giá kèm danh sách phòng đã gắn + giá/điều kiện đã lưu cho từng phòng.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), 'view_any_price::board')) {
            return $this->forbidden();
        }

        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        $board->load(['items.product', 'items.timeSlots']);

        return response()->json($this->toDetailItem($board));
    }

    /**
     * POST /api/admin/price-boards
     *
     * Body:
     *  - name (bắt buộc), note, start_date, end_date (Y-m-d, để trống = không giới hạn), is_active
     *  - pricing_mode : 'override' (mặc định, tự nhập giá từng phòng) | 'adjustment' (chỉ cộng/trừ %
     *    hoặc số tiền cố định trên giá gốc — xem PriceBoard::MODE_ADJUSTMENT)
     *  - adjustment_type ('percent'|'fixed'), adjustment_value : BẮT BUỘC khi pricing_mode=adjustment
     *  - product_ids : mảng id phòng — BẮT BUỘC khi pricing_mode=adjustment (không cần nhập giá riêng
     *    từng phòng, chỉ cần chọn phòng áp dụng)
     *  - items : mảng chi tiết từng phòng — BẮT BUỘC khi pricing_mode=override, xem
     *    updateItemsValidation() để biết field theo từng styles của phòng
     *
     * Chặn lưu nếu khoảng ngày hiệu lực trùng bảng khác đang active cho cùng phòng
     * (PriceBoardSyncService::assertNoOverlap) → 422. Tạo xong áp giá NGAY nếu bảng đang active và
     * đang trong khoảng hiệu lực (resyncBoardProducts) — không chờ job chạy nửa đêm.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->userHasPermission($user, 'create_price::board')) {
            return $this->forbidden();
        }

        $data = $request->validate($this->boardValidationRules());
        $data = $this->itemsValidation($request, $data);

        if ($error = $this->assertProductsVisible($user, $this->productIdsFromPayload($data)))  {
            return $error;
        }

        $board = new PriceBoard();

        try {
            $service = app(PriceBoardSyncService::class);

            DB::transaction(function () use ($board, $data, $service) {
                $board->fill([
                    'name'             => $data['name'],
                    'note'             => $data['note'] ?? null,
                    'start_date'       => $data['start_date'] ?? null,
                    'end_date'         => $data['end_date'] ?? null,
                    'is_active'        => $data['is_active'] ?? true,
                    'pricing_mode'     => $data['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE,
                    'adjustment_type'  => $data['adjustment_type'] ?? null,
                    'adjustment_value' => $data['adjustment_value'] ?? null,
                ]);
                $board->save();

                $service->saveItems($board, $data);
                $service->assertNoOverlap($board);
            });
        } catch (\RuntimeException $e) {
            // DB::transaction() đã rollback board+items vừa tạo khi assertNoOverlap() ném lỗi bên
            // trong nó — không cần tự xoá lại gì thêm ở đây.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(PriceBoardSyncService::class)->resyncBoardProducts($board);

        return response()->json($this->toDetailItem($board->fresh(['items.product', 'items.timeSlots'])), 201);
    }

    /**
     * PUT/PATCH /api/admin/price-boards/{id}
     * Cùng field như store(), tất cả 'sometimes' — field không gửi lên giữ nguyên giá trị cũ. Gửi lại
     * 'items'/'product_ids' sẽ THAY THẾ TOÀN BỘ danh sách phòng đang gắn (giống Filament — phòng nào
     * không còn trong mảng mới sẽ bị gỡ khỏi bảng giá này, xem
     * PriceBoardSyncService::saveOverrideItems()/saveProductIds()).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $this->userHasPermission($user, 'update_price::board')) {
            return $this->forbidden();
        }

        $rules = $this->boardValidationRules();
        $rules['name'] = 'sometimes|string|max:255';
        $data  = $request->validate($rules);
        $data  = $this->itemsValidation($request, $data, required: false);

        if ($error = $this->assertProductsVisible($user, $this->productIdsFromPayload($data))) {
            return $error;
        }

        try {
            $service = app(PriceBoardSyncService::class);

            DB::transaction(function () use ($board, $data, $service) {
                $board->fill(collect($data)->only([
                    'name', 'note', 'start_date', 'end_date', 'is_active',
                    'pricing_mode', 'adjustment_type', 'adjustment_value',
                ])->toArray());
                $board->save();

                if (array_key_exists('items', $data) || array_key_exists('product_ids', $data)) {
                    $service->saveItems($board, $data);
                }

                $service->assertNoOverlap($board);
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        app(PriceBoardSyncService::class)->resyncBoardProducts($board);

        return response()->json($this->toDetailItem($board->fresh(['items.product', 'items.timeSlots'])));
    }

    /**
     * DELETE /api/admin/price-boards/{id}
     * Xoá bảng giá — cascade xoá price_board_items/price_board_time_slots (FK). Sau khi xoá, tính lại
     * giá ĐÚNG cho từng phòng từng gắn trong bảng này (bảng khác đang active thắng, hoặc khôi phục về
     * giá gốc) — KHÔNG tự ý ghi thẳng baseline của bảng vừa xoá, xem
     * PriceBoardSyncService::resyncProductsAfterBoardDeleted().
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $this->userHasPermission($user, 'delete_price::board')) {
            return $this->forbidden();
        }

        $service           = app(PriceBoardSyncService::class);
        $affectedProducts  = $service->productsAffectedByBoard($board);

        $board->delete();

        $service->resyncProductsAfterBoardDeleted($affectedProducts);

        return response()->json(['message' => 'Đã xoá bảng giá.']);
    }

    /**
     * POST /api/admin/price-boards/{id}/apply
     * Áp NGAY giá của bảng xuống các phòng đã gắn, BẤT KỂ ngày hiệu lực/is_active — dùng khi cần đổi
     * giá tức thời dù chưa tới ngày (giống nút "Áp dụng ngay" trong Filament).
     */
    public function apply(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $this->userHasPermission($user, 'update_price::board')) {
            return $this->forbidden();
        }

        app(PriceBoardSyncService::class)->applyBoard($board);

        return response()->json(['message' => 'Đã áp dụng bảng giá "' . $board->name . '".']);
    }

    /**
     * PATCH /api/admin/price-boards/{id}/active
     * Body: { "is_active": true|false } — bật/tắt nhanh, có hiệu lực NGAY (không chờ job nửa đêm),
     * giống ToggleColumn trong Filament. Bật lại phải qua đúng kiểm tra trùng ngày — bật thất bại nếu
     * trùng lịch với 1 bảng khác đang active cho cùng phòng (422, board GIỮ NGUYÊN trạng thái cũ).
     */
    public function setActive(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $this->userHasPermission($user, 'update_price::board')) {
            return $this->forbidden();
        }

        $data = $request->validate(['is_active' => 'required|boolean']);

        $service = app(PriceBoardSyncService::class);

        if ($data['is_active']) {
            try {
                $service->assertNoOverlap($board);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        $board->update(['is_active' => $data['is_active']]);
        $service->resyncBoardProducts($board);

        return response()->json($this->toDetailItem($board->fresh(['items.product', 'items.timeSlots'])));
    }

    /**
     * GET /api/admin/price-boards/{id}/history?per_page=
     * Lịch sử thay đổi giá (price_board_price_logs) ghi nhận cho bảng này — chỉ có dòng khi giá THẬT
     * SỰ đổi (xem PriceBoardSyncService::logPriceChange()).
     */
    public function history(Request $request, string $id): JsonResponse
    {
        $user  = $request->user();
        $board = $this->visibleBoard($id);

        if (! $board) {
            return response()->json(['message' => 'Không tìm thấy bảng giá.'], 404);
        }

        if (! $this->userHasPermission($user, 'view_any_price::board')) {
            return $this->forbidden();
        }

        $perPage = (int) $request->integer('per_page', 20);

        $logs = PriceBoardPriceLog::where('price_board_id', $board->id)
            ->with(['product:id,name', 'changedByUser:id,fullname'])
            ->orderByDesc('created_at')
            ->paginate($perPage > 0 ? min($perPage, 100) : 20);

        $logs->getCollection()->transform(fn (PriceBoardPriceLog $log) => [
            'id'         => $log->id,
            'room'       => $log->product ? ['id' => $log->product->id, 'name' => $log->product->name] : null,
            'old_price'  => $log->old_price,
            'new_price'  => $log->new_price,
            'old_slots'  => $log->old_slots,
            'new_slots'  => $log->new_slots,
            'changed_by' => $log->changedByUser?->fullname,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return response()->json($logs);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    private function boardValidationRules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'note'              => 'nullable|string',
            'start_date'        => 'nullable|date_format:Y-m-d',
            'end_date'          => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'is_active'         => 'sometimes|boolean',
            'pricing_mode'      => 'sometimes|in:override,adjustment',
            'adjustment_type'   => 'required_if:pricing_mode,adjustment|nullable|in:percent,fixed',
            'adjustment_value'  => 'required_if:pricing_mode,adjustment|nullable|numeric',
        ];
    }

    /**
     * 'items'/'product_ids' được validate RIÊNG (không gộp vào boardValidationRules()) vì rule của
     * 'items.*' phụ thuộc styles của TỪNG phòng (đọc từ DB, không thể khai báo tĩnh) — xem
     * PriceBoardSyncService::saveOverrideItems() để đối chiếu field CHÍNH XÁC được ghi xuống.
     */
    private function itemsValidation(Request $request, array $data, bool $required = true): array
    {
        $mode = $data['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE;

        if ($mode === PriceBoard::MODE_ADJUSTMENT) {
            $rules = ['product_ids' => ($required ? 'required' : 'sometimes') . '|array|min:1'];
            $rules['product_ids.*'] = 'string|exists:products,id';

            return array_merge($data, $request->validate($rules));
        }

        $rules = [
            'items'                              => ($required ? 'required' : 'sometimes') . '|array|min:1',
            'items.*.product_id'                 => 'required|string|exists:products,id',
            'items.*.full_booking_discount'      => 'nullable|string|max:50',
            'items.*.bulk_discount_rules'        => 'nullable|array',
            'items.*.bulk_discount_rules.*.slots'    => 'required_with:items.*.bulk_discount_rules|integer|min:1',
            'items.*.bulk_discount_rules.*.discount' => 'required_with:items.*.bulk_discount_rules|numeric|min:0|max:100',
            'items.*.room_config'                => 'nullable|array',
            'items.*.room_config.max_free_guests' => 'nullable|integer|min:0',
            'items.*.room_config.extra_guest_fee' => 'nullable|numeric|min:0',
            // styles=2 (theo ngày) — bỏ qua nếu phòng styles=1, xem PriceBoardSyncService
            'items.*.price'                      => 'nullable|numeric|min:0',
            'items.*.default_checkin'            => 'nullable|date_format:H:i',
            'items.*.default_checkout'           => 'nullable|date_format:H:i',
            'items.*.deposit_min_nights'         => 'nullable|integer|min:1',
            'items.*.deposit_multi_night'        => 'nullable|integer|min:0|max:100',
            // styles=1 (theo khung giờ)
            'items.*.room_time_slots'                    => 'nullable|array',
            'items.*.room_time_slots.*.timeslot_id'      => 'required_with:items.*.room_time_slots|integer|exists:time_slots,id',
            'items.*.room_time_slots.*.price'            => 'required_with:items.*.room_time_slots|numeric|min:0',
            'items.*.room_time_slots.*.over_night'       => 'nullable|boolean',
            'items.*.room_time_slots.*.status'           => 'nullable|string|in:available,disabled',
        ];

        $validated = $request->validate($rules);

        // Chuyển 'room_config.{max_free_guests,extra_guest_fee}' (JSON lồng nhau, thân thiện API) và
        // 'room_time_slots' (tên rõ nghĩa hơn) sang ĐÚNG shape phẳng mà
        // PriceBoardSyncService::saveOverrideItems() đang đọc ('room_config_max_free_guests' /
        // 'room_config_extra_guest_fee' / 'roomTimeSlots') — tách khỏi cách Livewire form đặt tên field
        // (vốn phẳng vì ràng buộc kỹ thuật của form, không phải shape hợp lý cho JSON API).
        if (isset($validated['items'])) {
            $validated['items'] = collect($validated['items'])->map(function (array $item) {
                $item['room_config_max_free_guests'] = $item['room_config']['max_free_guests'] ?? 2;
                $item['room_config_extra_guest_fee']  = $item['room_config']['extra_guest_fee'] ?? 0;
                $item['roomTimeSlots']                = $item['room_time_slots'] ?? [];
                unset($item['room_config'], $item['room_time_slots']);

                return $item;
            })->all();
        }

        return array_merge($data, $validated);
    }

    private function productIdsFromPayload(array $data): array
    {
        if (! empty($data['product_ids'])) {
            return array_map('intval', $data['product_ids']);
        }

        return collect($data['items'] ?? [])->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    // ── Access control ──────────────────────────────────────────────────────

    private function userHasPermission(User $user, string $permission): bool
    {
        return $user->isSuperAdmin() || $user->can($permission);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Không có quyền thực hiện thao tác này.'], 403);
    }

    private function visibleBoard(string $id): ?PriceBoard
    {
        return PriceBoard::where('is_default', false)->where('id', $id)->first();
    }

    /** Không phải super_admin thì MỌI phòng nhắc tới trong product_ids/items phải thuộc đúng
     *  partner_id + allowedCategoryIds() của user (giống RoomPricingController::userCanAccessRoom()) —
     *  chặn 1 đối tác (nếu được cấp quyền Shield riêng) đổi giá phòng ngoài phạm vi qua bảng giá. */
    private function assertProductsVisible(User $user, array $productIds): ?JsonResponse
    {
        if (empty($productIds) || $user->isSuperAdmin()) {
            return null;
        }

        $categoryIds  = $user->allowedCategoryIds();
        $visibleCount = Product::whereIn('id', $productIds)
            ->where('partner_id', $user->partner_id)
            ->when(! empty($categoryIds), fn ($q) => $q->whereHas(
                'categories',
                fn ($q2) => $q2->whereIn('categories.id', $categoryIds)
            ))
            ->count();

        if ($visibleCount !== count(array_unique($productIds))) {
            return response()->json(['message' => 'Một hoặc nhiều phòng không thuộc phạm vi quản lý của tài khoản.'], 422);
        }

        return null;
    }

    // ── Response formatting ─────────────────────────────────────────────────

    private function toListItem(PriceBoard $board): array
    {
        return [
            'id'               => $board->id,
            'name'             => $board->name,
            'note'             => $board->note,
            'start_date'       => $board->start_date?->toDateString(),
            'end_date'         => $board->end_date?->toDateString(),
            'is_active'        => (bool) $board->is_active,
            'status'           => $this->statusLabel($board),
            'pricing_mode'     => $board->pricing_mode ?? PriceBoard::MODE_OVERRIDE,
            'adjustment_type'  => $board->adjustment_type,
            'adjustment_value' => $board->adjustment_value === null ? null : (float) $board->adjustment_value,
            'rooms_count'      => $board->items_count ?? $board->items()->count(),
            'created_at'       => $board->created_at?->toIso8601String(),
            'updated_at'       => $board->updated_at?->toIso8601String(),
        ];
    }

    private function toDetailItem(PriceBoard $board): array
    {
        $item = $this->toListItem($board);

        $item['items'] = $board->items->map(fn ($boardItem) => [
            'id'                     => $boardItem->id,
            'room' => [
                'id'   => $boardItem->product_id,
                'name' => $boardItem->product?->name,
            ],
            'price'                  => $boardItem->price === null ? null : (float) $boardItem->price,
            'full_booking_discount'  => $boardItem->full_booking_discount,
            'bulk_discount_rules'    => $boardItem->bulk_discount_rules,
            'room_config'            => $boardItem->room_config,
            'default_checkin'        => $boardItem->default_checkin,
            'default_checkout'       => $boardItem->default_checkout,
            'deposit_1_night'        => $boardItem->deposit_1_night,
            'deposit_multi_night'    => $boardItem->deposit_multi_night,
            'deposit_min_nights'     => $boardItem->deposit_min_nights,
            'room_time_slots'        => $boardItem->timeSlots->map(fn ($slot) => [
                'timeslot_id' => $slot->timeslot_id,
                'price'       => (float) $slot->price,
                'over_night'  => (bool) $slot->over_night,
                'status'      => $slot->status,
            ])->values(),
        ])->values();

        return $item;
    }

    private function statusLabel(PriceBoard $board): string
    {
        return match (true) {
            ! $board->is_active => 'Đã tắt',
            $board->coversDate() => 'Đang áp dụng',
            $board->start_date && now()->startOfDay()->lt($board->start_date) => 'Chờ áp dụng',
            default => 'Hết hạn',
        };
    }
}
