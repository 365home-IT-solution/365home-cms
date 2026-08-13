<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

/**
 * Quản lý MÃ KHUYẾN MÃI (bảng `coupons`, model Modules\Promotion\App\Models\Coupon) — CRUD đầy đủ.
 * Field khớp Modules\Coupon\App\Filament\Resources\CouponResource\Forms\CouponForm, cộng thêm
 * 'validity_days' (không có ở Filament trước đây — mới thêm, xem MembershipService::grantTemplateCoupon()):
 * coupon có validity_days KHÔNG dùng trực tiếp — chỉ có tác dụng khi được gắn làm "mẫu" cho 1 hạng
 * thành viên (POST .../membership-tiers/{id}/coupons), lúc đó mỗi khách lên hạng sẽ được cấp 1 BẢN
 * SAO RIÊNG với hạn = ngày lên hạng + validity_days, thay vì dùng chung 1 coupon hạn cố định.
 *
 * Phạm vi hiển thị/sửa: super_admin xem & sửa mọi coupon; user thường chỉ xem/sửa coupon thuộc đúng
 * đối tác mình (coupons.partner_id) — global scope BelongsToPartner KHÔNG áp dụng ngoài Filament
 * panel nên phải lọc thủ công (cùng nguyên tắc PromotionController).
 */
class CouponController extends Controller
{
    public const APPLY_TYPES = ['all_rooms', 'specific_room', 'specific_slot'];

    /**
     * GET /api/admin/coupons
     * Query params:
     *  - search      : lọc theo code/name
     *  - type        : percentage|fixed
     *  - apply_type  : all_rooms|specific_room|specific_slot
     *  - is_active   : 1/0
     *  - customer_id : lọc coupon cá nhân của 1 khách hàng (personal — customer_id trực tiếp)
     *  - categories  : slug chi nhánh gốc, cách nhau dấu phẩy — trả về coupon 'all_rooms' (áp dụng
     *                  mọi chi nhánh) HOẶC coupon specific_room/specific_slot mà phòng gắn thuộc
     *                  chi nhánh đó
     *  - per_page    : mặc định 20
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = $this->visibleCouponsQuery($user)->with(['room:id,name', 'customer:id,fullname,phone']);

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('apply_type')) {
            $query->where('apply_type', $request->string('apply_type'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->string('customer_id'));
        }

        if ($request->filled('categories')) {
            $branchIds = $this->resolveRootCategoryIds((string) $request->string('categories'));
            $query->where(function (Builder $q) use ($branchIds) {
                $q->where('apply_type', 'all_rooms')
                    ->orWhereHas('room.categories', fn ($q2) => $q2->whereIn('categories.id', $branchIds ?: [-1]));
            });
        }

        $coupons = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 20));
        $coupons->getCollection()->transform(fn (Coupon $c) => $this->toListItem($c));

        return response()->json($coupons);
    }

    /**
     * GET /api/admin/coupons/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $coupon = $this->visibleCouponsQuery($request->user())
            ->with(['room:id,name', 'customer:id,fullname,phone', 'roomTimeSlots.timeSlot'])
            ->find($id);

        if (! $coupon) {
            return response()->json(['message' => 'Không tìm thấy mã khuyến mãi.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($coupon)]);
    }

    /**
     * POST /api/admin/coupons
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->isSuperAdmin() && empty($user->partner_id)) {
            return response()->json(['message' => 'Tài khoản không thuộc đối tác nào, không thể tạo mã khuyến mãi.'], 403);
        }

        $data = $request->validate($this->rules());

        $valueError = $this->validatePercentValue($data['type'], (float) $data['value']);
        if ($valueError) {
            return response()->json(['message' => $valueError], 422);
        }

        $roomError = $this->validateRoomOwnership($user, $data);
        if ($roomError) {
            return response()->json(['message' => $roomError], 422);
        }

        $partnerId = $user->isSuperAdmin() ? ($data['partner_id'] ?? null) : $user->partner_id;

        $coupon = DB::transaction(function () use ($data, $partnerId, $user) {
            $coupon = Coupon::create([
                'partner_id'      => $partnerId,
                'code'            => strtoupper($data['code']),
                'name'            => $data['name'],
                'description'     => $data['description'] ?? null,
                'type'            => $data['type'],
                'value'           => $data['value'],
                'apply_type'      => $data['apply_type'],
                'room_id'         => $data['apply_type'] !== 'all_rooms' ? $data['room_id'] : null,
                'min_order_value' => $data['min_order_value'] ?? null,
                'max_discount'    => $data['max_discount'] ?? null,
                'usage_limit'     => $data['usage_limit'] ?? null,
                'used_count'      => 0,
                'start_at'        => $data['start_at'],
                'end_at'          => $data['end_at'] ?? null,
                'validity_days'   => $data['validity_days'] ?? null,
                'is_active'       => $data['is_active'] ?? true,
                'customer_id'     => $data['customer_id'] ?? null,
                'created_by'      => $user->id,
            ]);

            if ($data['apply_type'] === 'specific_slot' && ! empty($data['room_time_slot_ids'])) {
                $coupon->roomTimeSlots()->sync($data['room_time_slot_ids']);
            }

            return $coupon;
        });

        return response()->json(['data' => $this->toDetailItem($coupon->fresh(['room:id,name', 'customer:id,fullname,phone']))], 201);
    }

    /**
     * PUT|POST /api/admin/coupons/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user   = $request->user();
        $coupon = $this->visibleCouponsQuery($user)->find($id);

        if (! $coupon) {
            return response()->json(['message' => 'Không tìm thấy mã khuyến mãi.'], 404);
        }

        $data = $request->validate($this->rules(isUpdate: true, id: $id));

        $type  = $data['type'] ?? $coupon->type;
        $value = array_key_exists('value', $data) ? (float) $data['value'] : (float) $coupon->value;
        $valueError = $this->validatePercentValue($type, $value);
        if ($valueError) {
            return response()->json(['message' => $valueError], 422);
        }

        $applyType = $data['apply_type'] ?? $coupon->apply_type;
        $roomError = $this->validateRoomOwnership($user, [
            'apply_type' => $applyType,
            'room_id'    => $data['room_id'] ?? $coupon->room_id,
        ]);
        if ($roomError) {
            return response()->json(['message' => $roomError], 422);
        }

        DB::transaction(function () use ($coupon, $data, $applyType) {
            $fields = collect($data)->only([
                'name', 'description', 'type', 'value', 'apply_type', 'min_order_value',
                'max_discount', 'usage_limit', 'start_at', 'end_at', 'validity_days', 'is_active',
            ])->toArray();

            if (array_key_exists('code', $data)) {
                $fields['code'] = strtoupper($data['code']);
            }

            if (array_key_exists('apply_type', $data) || array_key_exists('room_id', $data)) {
                $fields['room_id'] = $applyType !== 'all_rooms' ? ($data['room_id'] ?? $coupon->room_id) : null;
            }

            $coupon->update($fields);

            if ($applyType === 'specific_slot' && array_key_exists('room_time_slot_ids', $data)) {
                $coupon->roomTimeSlots()->sync($data['room_time_slot_ids']);
            } elseif ($applyType !== 'specific_slot') {
                $coupon->roomTimeSlots()->sync([]);
            }
        });

        return response()->json(['data' => $this->toDetailItem($coupon->fresh(['room:id,name', 'customer:id,fullname,phone']))]);
    }

    /**
     * DELETE /api/admin/coupons/{id}
     * Chặn xoá nếu coupon đang là MẪU của 1+ hạng thành viên (membership_tier_coupon) hoặc đã có
     * bản sao cá nhân được cấp ra (template_coupon_id) — tránh mất cấu hình/lịch sử đang dùng.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $coupon = $this->visibleCouponsQuery($request->user())->find($id);

        if (! $coupon) {
            return response()->json(['message' => 'Không tìm thấy mã khuyến mãi.'], 404);
        }

        if ($coupon->membershipTiers()->exists()) {
            return response()->json(['message' => 'Không thể xoá — mã khuyến mãi đang là mẫu voucher của 1 hoặc nhiều hạng thành viên.'], 422);
        }

        if ($coupon->personalClones()->exists()) {
            return response()->json(['message' => 'Không thể xoá — đã có bản sao cá nhân được cấp từ mã khuyến mãi này.'], 422);
        }

        $coupon->customers()->detach();
        $coupon->roomTimeSlots()->detach();
        $coupon->delete();

        return response()->json(['message' => 'Đã xoá mã khuyến mãi.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rules(bool $isUpdate = false, ?int $id = null): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';
        $ignoreId = $isUpdate ? ",{$id}" : '';

        return [
            'code'        => "{$required}|string|max:50|alpha_num|unique:coupons,code{$ignoreId}",
            'name'        => "{$required}|string|max:255",
            'description' => 'nullable|string|max:500',
            'type'        => "{$required}|in:percentage,fixed",
            'value'       => "{$required}|numeric|min:0",

            'apply_type'         => "{$required}|in:".implode(',', self::APPLY_TYPES),
            'room_id'            => 'nullable|integer|exists:products,id',
            'room_time_slot_ids' => 'nullable|array',
            'room_time_slot_ids.*' => 'integer|exists:room_time_slots,id',

            'min_order_value' => 'nullable|numeric|min:0',
            'max_discount'    => 'nullable|numeric|min:0',
            'usage_limit'     => 'nullable|integer|min:1',
            'start_at'        => "{$required}|date",
            'end_at'          => 'nullable|date|after:start_at',
            // Chỉ có ý nghĩa khi coupon này được gắn làm mẫu voucher cho 1 hạng thành viên — xem
            // docblock class. Không bắt buộc cho coupon dùng bình thường.
            'validity_days'   => 'nullable|integer|min:1|max:365',
            'is_active'       => 'nullable|boolean',

            // Tạo thẳng 1 coupon CÁ NHÂN cho 1 khách (tương đương coupon tự động do hệ thống cấp) —
            // KHÁC với API gán mã có sẵn cho khách (POST .../customers/{id}/assign-coupon).
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'partner_id'  => 'nullable|uuid|exists:partners,id',
        ];
    }

    private function validatePercentValue(string $type, float $value): ?string
    {
        if ($type === 'percentage' && $value > 100) {
            return 'Giá trị mã khuyến mãi theo % không được vượt quá 100.';
        }

        return null;
    }

    // apply_type != all_rooms bắt buộc chọn phòng, và phòng đó phải nằm trong phạm vi user được xem.
    private function validateRoomOwnership(User $user, array $data): ?string
    {
        if (($data['apply_type'] ?? 'all_rooms') === 'all_rooms') {
            return null;
        }

        if (empty($data['room_id'])) {
            return 'Bắt buộc chọn phòng khi áp dụng cho 1 phòng/khung giờ cụ thể.';
        }

        if ($user->isSuperAdmin()) {
            return null;
        }

        $room = Product::withoutGlobalScopes()->find($data['room_id']);
        if (! $room || $room->partner_id !== $user->partner_id) {
            return 'Không có quyền gán mã khuyến mãi vào phòng này.';
        }

        return null;
    }

    private function visibleCouponsQuery(User $user): Builder
    {
        $query = Coupon::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        return $query;
    }

    // Slug chi nhánh GỐC (parent_id=null) → id, cùng quy tắc CustomerController::resolveRootCategoryIdsFromSlugs().
    private function resolveRootCategoryIds(string $slugsParam): array
    {
        $slugs = collect(explode(',', $slugsParam))->map(fn ($s) => trim($s))->filter()->values();

        if ($slugs->isEmpty()) {
            return [];
        }

        return Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereIn('slug', $slugs)
            ->pluck('id')
            ->toArray();
    }

    private function toListItem(Coupon $coupon): array
    {
        return [
            'id'              => $coupon->id,
            'code'            => $coupon->code,
            'name'            => $coupon->name,
            'type'            => $coupon->type,
            'value'           => $coupon->value,
            'apply_type'      => $coupon->apply_type,
            'room'            => $coupon->room ? ['id' => $coupon->room->id, 'name' => $coupon->room->name] : null,
            'min_order_value' => $coupon->min_order_value,
            'usage_limit'     => $coupon->usage_limit,
            'used_count'      => $coupon->used_count,
            'start_at'        => optional($coupon->start_at)->format('Y-m-d H:i:s'),
            'end_at'          => optional($coupon->end_at)->format('Y-m-d H:i:s'),
            'validity_days'   => $coupon->validity_days,
            'is_active'       => (bool) $coupon->is_active,
            'is_personal'     => $coupon->isPersonal(),
            'customer'        => $coupon->customer ? [
                'id' => $coupon->customer->id, 'fullname' => $coupon->customer->fullname, 'phone' => $coupon->customer->phone,
            ] : null,
        ];
    }

    private function toDetailItem(Coupon $coupon): array
    {
        return array_merge($this->toListItem($coupon), [
            'description'        => $coupon->description,
            'max_discount'       => $coupon->max_discount,
            'partner_id'         => $coupon->partner_id,
            'template_coupon_id' => $coupon->template_coupon_id,
            'room_time_slots'    => $coupon->relationLoaded('roomTimeSlots')
                ? $coupon->roomTimeSlots->map(fn ($s) => ['id' => $s->id, 'label' => $s->timeSlot?->label])->values()
                : [],
            'created_at' => optional($coupon->created_at)->toISOString(),
            'updated_at' => optional($coupon->updated_at)->toISOString(),
        ]);
    }
}
