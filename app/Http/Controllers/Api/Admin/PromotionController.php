<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Promotion\App\Models\Promotion;

/**
 * Quản lý ƯU ĐÃI (bảng `promotions`, module Promotion) — CRUD đầy đủ, KHÁC với
 * App\Http\Controllers\Api\Admin\RoomPromotionController (chỉ gán/gỡ 1 ưu đãi CÓ SẴN cho khung giờ
 * của 1 phòng, không tạo/sửa/xoá bản ghi Promotion).
 *
 * Field khớp Modules\Promotion\App\Filament\Resources\PromotionResource\Forms\PromotionForm:
 *  - name, type (percentage|fixed|increase_percentage|increase_fixed), value, is_active, start_at,
 *    end_at, description, lable_client, image, tuimu_rewards.
 *  - categories : chi nhánh/khu vực áp dụng ưu đãi — gán trực tiếp qua bảng `categorizables`
 *    (categorizable_type=Promotion::class, dùng chung cơ chế Modules\Category\Traits\Categorizable
 *    mà Product đang dùng). Validate/lọc theo ĐÚNG quy tắc phân quyền chi nhánh hiện có của hệ
 *    thống — xem validateCategoryIds() (giống hệt ProductController::validateCategoryIds()).
 *
 * Phạm vi hiển thị/sửa: super_admin xem & sửa mọi ưu đãi; user thường chỉ xem/sửa ưu đãi thuộc đúng
 * đối tác mình (promotions.partner_id) — global scope BelongsToPartner KHÔNG áp dụng ngoài Filament
 * panel nên phải lọc thủ công (cùng nguyên tắc ProductController/CategoryController/RoomPromotionController).
 */
class PromotionController extends Controller
{
    private const IMAGE_RULE = 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120';

    private const PERCENT_TYPES = ['percentage', 'increase_percentage'];

    /**
     * GET /api/admin/promotions
     * Query params:
     *  - search      : lọc theo tên ưu đãi
     *  - type        : lọc theo type (percentage|fixed|increase_percentage|increase_fixed)
     *  - is_active   : 1/0 — lọc theo trạng thái bật/tắt
     *  - category_id : lọc theo 1 chi nhánh/khu vực (gồm cả khu vực con nếu truyền chi nhánh gốc)
     *  - categories  : mảng nhiều category_id — lọc ưu đãi thuộc BẤT KỲ chi nhánh/khu vực nào trong
     *                  danh sách (mỗi id cũng tự mở rộng ra khu vực con như category_id)
     *  - per_page    : mặc định 20
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->visiblePromotionsQuery($user)->with(['categories:id,name,slug,parent_id']);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category_id')) {
            $categoryIds = $this->expandCategoryIds((int) $request->integer('category_id'));
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds));
        }

        if ($request->filled('categories')) {
            $ids = array_map('intval', (array) $request->input('categories'));
            $expanded = collect($ids)->flatMap(fn ($id) => $this->expandCategoryIds($id))->unique()->values()->all();
            $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $expanded));
        }

        $promotions = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));
        $promotions->getCollection()->transform(fn (Promotion $p) => $this->toListItem($p));

        return response()->json($promotions);
    }

    /**
     * GET /api/admin/promotions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $promotion = $this->visiblePromotionsQuery($request->user())
            ->with(['categories:id,name,slug,parent_id'])
            ->find($id);

        if (! $promotion) {
            return response()->json(['message' => 'Không tìm thấy ưu đãi.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($promotion)]);
    }

    /**
     * POST /api/admin/promotions (multipart/form-data nếu gửi kèm ảnh)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào, không thể tạo ưu đãi.'], 403);
        }

        $data = $request->validate($this->rules());

        $valueError = $this->validatePercentValue($data['type'], (float) $data['value']);
        if ($valueError) {
            return response()->json(['message' => $valueError], 422);
        }

        if (! empty($data['categories'])) {
            $categoryError = $this->validateCategoryIds($user, $data['categories']);
            if ($categoryError) {
                return response()->json(['message' => $categoryError], 422);
            }
        }

        $partnerId = $user->isSuperAdmin() ? ($data['partner_id'] ?? null) : $user->partner_id;
        if ($user->isSuperAdmin() && empty($partnerId)) {
            return response()->json(['message' => 'Bắt buộc chọn đối tác sở hữu (partner_id) khi tạo ưu đãi.'], 422);
        }

        $promotion = DB::transaction(function () use ($data, $partnerId, $user) {
            $promotion = Promotion::create([
                'partner_id'    => $partnerId,
                'name'          => $data['name'],
                'description'   => $data['description'] ?? null,
                'type'          => $data['type'],
                'value'         => $data['value'],
                'tuimu_rewards' => $data['tuimu_rewards'] ?? null,
                'start_at'      => $data['start_at'],
                'end_at'        => $data['end_at'],
                'lable_client'  => $data['lable_client'] ?? null,
                'is_active'     => $data['is_active'] ?? true,
                'created_by'    => $user->id,
            ]);

            if (! empty($data['categories'])) {
                $promotion->categories()->sync($data['categories']);
            }

            return $promotion;
        });

        if ($request->hasFile('image')) {
            $promotion->update(['image' => $request->file('image')->store('promotions', 'public')]);
        }

        return response()->json(['data' => $this->toWriteItem($promotion->fresh(['categories:id,name,slug,parent_id']))], 201);
    }

    /**
     * PUT|POST /api/admin/promotions/{id} (nhận cả PUT lẫn POST — dùng POST khi cần gửi kèm ảnh,
     * PHP không tự parse multipart cho method PUT thật).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user      = $request->user();
        $promotion = $this->visiblePromotionsQuery($user)->find($id);

        if (! $promotion) {
            return response()->json(['message' => 'Không tìm thấy ưu đãi.'], 404);
        }

        $data = $request->validate($this->rules(isUpdate: true));

        $type  = $data['type'] ?? $promotion->type;
        $value = array_key_exists('value', $data) ? (float) $data['value'] : (float) $promotion->value;
        $valueError = $this->validatePercentValue($type, $value);
        if ($valueError) {
            return response()->json(['message' => $valueError], 422);
        }

        if (array_key_exists('categories', $data)) {
            $categoryError = $this->validateCategoryIds($user, $data['categories']);
            if ($categoryError) {
                return response()->json(['message' => $categoryError], 422);
            }
        }

        DB::transaction(function () use ($promotion, $data) {
            $promotion->update(collect($data)->only([
                'name', 'description', 'type', 'value', 'tuimu_rewards',
                'start_at', 'end_at', 'lable_client', 'is_active',
            ])->toArray());

            if (array_key_exists('categories', $data)) {
                $promotion->categories()->sync($data['categories']);
            }
        });

        if ($request->hasFile('image')) {
            $promotion->update(['image' => $request->file('image')->store('promotions', 'public')]);
        }

        return response()->json(['data' => $this->toWriteItem($promotion->fresh(['categories:id,name,slug,parent_id']))]);
    }

    /**
     * DELETE /api/admin/promotions/{id}
     * Chặn xoá nếu ưu đãi đang được gán cho khung giờ của phòng nào (promotion_room_time_slot) —
     * tránh mất dữ liệu tham chiếu đang dùng để tính giá (xem RoomPromotionController).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $promotion = $this->visiblePromotionsQuery($request->user())->find($id);

        if (! $promotion) {
            return response()->json(['message' => 'Không tìm thấy ưu đãi.'], 404);
        }

        if ($promotion->roomTimeSlots()->exists()) {
            return response()->json(['message' => 'Không thể xoá — ưu đãi đang được gán cho khung giờ của phòng.'], 422);
        }

        $promotion->categories()->detach();
        $promotion->delete();

        return response()->json(['message' => 'Đã xoá ưu đãi.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'name'          => "{$required}|string|max:255",
            'type'          => "{$required}|in:".implode(',', ['percentage', 'fixed', ...self::PERCENT_TYPES]),
            'value'         => "{$required}|numeric|min:0",
            'is_active'     => 'nullable|boolean',
            'start_at'      => "{$required}|date",
            'end_at'        => "{$required}|date|after:start_at",
            'description'   => 'nullable|string|max:500',
            'lable_client'  => 'nullable|string|max:100',
            'tuimu_rewards' => 'nullable|string',
            'image'         => self::IMAGE_RULE,

            'categories'    => 'nullable|array',
            'categories.*'  => 'integer|exists:categories,id',

            'partner_id'    => 'nullable|uuid|exists:partners,id',
        ];
    }

    // Ưu đãi kiểu %  (percentage/increase_percentage) không được vượt 100 — khớp maxValue(100) có
    // điều kiện của PromotionForm (Filament).
    private function validatePercentValue(string $type, float $value): ?string
    {
        if (in_array($type, self::PERCENT_TYPES, true) && $value > 100) {
            return 'Giá trị ưu đãi theo % không được vượt quá 100.';
        }

        return null;
    }

    // Chi nhánh/khu vực gán cho ưu đãi phải nằm trong phạm vi user được phép chọn — cùng quy tắc
    // ProductController::validateCategoryIds() (không cho user thường gán ưu đãi vào chi nhánh
    // ngoài quyền hạn dù biết đúng category_id).
    private function validateCategoryIds(User $user, array $categoryIds): ?string
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $allowed = $user->visibleProductCategoryIds();
        $invalid = array_diff($categoryIds, $allowed);

        if (! empty($invalid)) {
            return 'Không có quyền gán ưu đãi vào chi nhánh/khu vực: '.implode(', ', $invalid).'.';
        }

        return null;
    }

    private function visiblePromotionsQuery(User $user): Builder
    {
        $query = Promotion::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);

            $allowedCategoryIds = $user->allowedCategoryIds();
            if (! empty($allowedCategoryIds)) {
                $query->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allowedCategoryIds));
            }
        }

        return $query;
    }

    // Mở rộng 1 category (chi nhánh gốc HOẶC khu vực con) ra chính nó + toàn bộ danh mục con —
    // giống hệt ProductController::expandCategoryIds().
    private function expandCategoryIds(int $categoryId): array
    {
        $allIds       = [$categoryId];
        $currentLevel = [$categoryId];

        while (! empty($currentLevel)) {
            $children = Category::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            $children = array_diff($children, $allIds);
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return $allIds;
    }

    private function toListItem(Promotion $promotion): array
    {
        return [
            'id'           => $promotion->id,
            'name'         => $promotion->name,
            'type'         => $promotion->type,
            'value'        => $promotion->value,
            'lable_client' => $promotion->lable_client,
            'is_active'    => (bool) $promotion->is_active,
            'start_at'     => optional($promotion->start_at)->format('Y-m-d H:i:s'),
            'end_at'       => optional($promotion->end_at)->format('Y-m-d H:i:s'),
            'created_by'   => $promotion->created_by,
            'categories'   => $promotion->categories->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
            ])->values(),
        ];
    }

    private function toDetailItem(Promotion $promotion): array
    {
        return array_merge($this->toListItem($promotion), [
            'description'   => $promotion->description,
            'tuimu_rewards' => $promotion->tuimu_rewards,
            'image_url'     => $promotion->image ? Storage::disk('public')->url($promotion->image) : null,
            'partner_id'    => $promotion->partner_id,
            'created_at'    => optional($promotion->created_at)->toISOString(),
            'updated_at'    => optional($promotion->updated_at)->toISOString(),
        ]);
    }

    // Response cho store()/update() — bỏ 'tuimu_rewards' và 'categories' so với toDetailItem() (dùng
    // ở show()/index()) theo yêu cầu rút gọn kết quả trả về của tạo/sửa.
    private function toWriteItem(Promotion $promotion): array
    {
        return collect($this->toDetailItem($promotion))->except(['tuimu_rewards', 'categories'])->all();
    }
}
