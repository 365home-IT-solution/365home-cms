<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipTier;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản lý HẠNG THÀNH VIÊN (bảng `membership_tiers`) — CRUD đầy đủ, cộng thêm endpoint gắn/gỡ mã
 * khuyến mãi MẪU cho hạng (POST .../coupons) để cấu hình "khách vào hạng X tự động nhận voucher Y".
 * Field khớp App\Filament\Resources\MembershipTierResource.
 *
 * Hạng KHÔNG thuộc riêng đối tác nào (dùng chung toàn hệ thống, giống Customer) — không lọc theo
 * partner_id.
 *
 * Luồng cấp voucher tự động khi khách đạt hạng (xem App\Services\MembershipService):
 *  - welcome_coupon_* : cấu hình 1 voucher DUY NHẤT, tự tạo coupon CÁ NHÂN cho khách khi vừa đạt
 *    hạng, hạn dùng = lúc cấp + welcome_coupon_days.
 *  - coupons (POST .../coupons) : gắn thêm N coupon CÓ SẴN (tạo trước ở /api/admin/coupons) làm
 *    "mẫu" cho hạng — hỗ trợ NHIỀU voucher/hạng. Coupon mẫu có 'validity_days' sẽ được nhân bản
 *    thành 1 bản sao RIÊNG cho từng khách (hạn = lúc lên hạng + validity_days); coupon mẫu KHÔNG có
 *    validity_days thì mọi khách trong hạng dùng CHUNG 1 coupon với hạn cố định (start_at/end_at).
 */
class MembershipTierController extends Controller
{
    /**
     * GET /api/admin/membership-tiers
     * Query params: ?search= (tên/slug), ?is_active=, ?per_page= (mặc định 20, truyền 0 = lấy hết
     * không phân trang — dùng cho dropdown chọn hạng)
     */
    public function index(Request $request): JsonResponse
    {
        $query = MembershipTier::query()->withCount('customers');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%"));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy('sort_order');

        if ((int) $request->input('per_page', 20) === 0) {
            return response()->json(['data' => $query->get()->map(fn (MembershipTier $t) => $this->toListItem($t))]);
        }

        $tiers = $query->paginate($request->integer('per_page', 20));
        $tiers->getCollection()->transform(fn (MembershipTier $t) => $this->toListItem($t));

        return response()->json($tiers);
    }

    /**
     * GET /api/admin/membership-tiers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $tier = MembershipTier::withCount('customers')->with('coupons')->find($id);

        if (! $tier) {
            return response()->json(['message' => 'Không tìm thấy hạng thành viên.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($tier)]);
    }

    /**
     * POST /api/admin/membership-tiers
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $tier = MembershipTier::create([
            'name'                       => $data['name'],
            'slug'                       => $data['slug'],
            'description'                => $data['description'] ?? null,
            'color'                      => $data['color'] ?? '#6B7280',
            'icon'                       => $data['icon'] ?? 'heroicon-o-star',
            'sort_order'                 => $data['sort_order'] ?? 0,
            'min_spending'               => $data['min_spending'] ?? 0,
            'welcome_coupon_prefix'      => $data['welcome_coupon_prefix'] ?? null,
            'welcome_coupon_type'        => $data['welcome_coupon_type'] ?? 'percentage',
            'welcome_coupon_value'       => $data['welcome_coupon_value'] ?? 0,
            'welcome_coupon_days'        => $data['welcome_coupon_days'] ?? 30,
            'welcome_coupon_usage_limit' => $data['welcome_coupon_usage_limit'] ?? null,
            'is_active'                  => $data['is_active'] ?? true,
        ]);

        if (! empty($data['coupon_ids'])) {
            $tier->coupons()->sync($data['coupon_ids']);
            app(MembershipService::class)->distributeTierCoupons($tier);
        }

        return response()->json(['data' => $this->toDetailItem($tier->fresh(['coupons']))], 201);
    }

    /**
     * PUT|POST /api/admin/membership-tiers/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tier = MembershipTier::find($id);

        if (! $tier) {
            return response()->json(['message' => 'Không tìm thấy hạng thành viên.'], 404);
        }

        $data = $request->validate($this->rules(isUpdate: true, id: $id));

        $tier->update(collect($data)->only([
            'name', 'slug', 'description', 'color', 'icon', 'sort_order', 'min_spending',
            'welcome_coupon_prefix', 'welcome_coupon_type', 'welcome_coupon_value',
            'welcome_coupon_days', 'welcome_coupon_usage_limit', 'is_active',
        ])->toArray());

        if (array_key_exists('coupon_ids', $data)) {
            $tier->coupons()->sync($data['coupon_ids'] ?? []);
            app(MembershipService::class)->distributeTierCoupons($tier);
        }

        return response()->json(['data' => $this->toDetailItem($tier->fresh(['coupons']))]);
    }

    /**
     * DELETE /api/admin/membership-tiers/{id}
     * Chặn xoá nếu còn khách hàng đang giữ hạng này — tránh mất cấu hình hạng đang được dùng (dù
     * FK cho phép NULL tự động, xoá ngầm sẽ khiến khách "rơi" khỏi mọi hạng mà không ai biết).
     */
    public function destroy(int $id): JsonResponse
    {
        $tier = MembershipTier::withCount('customers')->find($id);

        if (! $tier) {
            return response()->json(['message' => 'Không tìm thấy hạng thành viên.'], 404);
        }

        if ($tier->customers_count > 0) {
            return response()->json(['message' => 'Không thể xoá — đang có khách hàng giữ hạng này.'], 422);
        }

        $tier->coupons()->detach();
        $tier->delete();

        return response()->json(['message' => 'Đã xoá hạng thành viên.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function rules(bool $isUpdate = false, ?int $id = null): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';
        $ignoreId = $isUpdate ? ",{$id}" : '';

        return [
            'name'        => "{$required}|string|max:100",
            'slug'        => "{$required}|string|max:50|alpha_dash|unique:membership_tiers,slug{$ignoreId}",
            'description' => 'nullable|string|max:500',
            'color'       => 'nullable|string|max:20',
            'icon'        => 'nullable|string|max:100',
            'sort_order'  => 'nullable|integer|min:0',
            'min_spending' => 'nullable|numeric|min:0',
            'is_active'    => 'nullable|boolean',

            'welcome_coupon_prefix'      => 'nullable|string|max:20',
            'welcome_coupon_type'        => 'nullable|in:percentage,fixed',
            'welcome_coupon_value'       => 'nullable|numeric|min:0',
            'welcome_coupon_days'        => 'nullable|integer|min:0',
            'welcome_coupon_usage_limit' => 'nullable|integer|min:1',

            // Danh sách id coupon có sẵn (tạo ở /api/admin/coupons) gắn làm mẫu cho hạng — thay thế
            // TOÀN BỘ danh sách cũ (sync). Không truyền field này = giữ nguyên; truyền [] = gỡ hết.
            'coupon_ids'   => 'sometimes|array',
            'coupon_ids.*' => 'integer|exists:coupons,id',
        ];
    }

    private function toListItem(MembershipTier $tier): array
    {
        return [
            'id'            => $tier->id,
            'name'          => $tier->name,
            'slug'          => $tier->slug,
            'color'         => $tier->color,
            'icon'          => $tier->icon,
            'sort_order'    => $tier->sort_order,
            'min_spending'  => $tier->min_spending,
            'is_active'     => (bool) $tier->is_active,
            'customer_count' => $tier->customers_count ?? 0,
        ];
    }

    private function toDetailItem(MembershipTier $tier): array
    {
        return array_merge($this->toListItem($tier), [
            'description'                => $tier->description,
            'welcome_coupon_prefix'      => $tier->welcome_coupon_prefix,
            'welcome_coupon_type'        => $tier->welcome_coupon_type,
            'welcome_coupon_value'       => $tier->welcome_coupon_value,
            'welcome_coupon_days'        => $tier->welcome_coupon_days,
            'welcome_coupon_usage_limit' => $tier->welcome_coupon_usage_limit,
            'coupons'                    => $tier->relationLoaded('coupons')
                ? $tier->coupons->map(fn ($c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                    'type' => $c->type, 'value' => $c->value,
                    'min_order_value' => $c->min_order_value, 'validity_days' => $c->validity_days,
                ])->values()
                : [],
            'created_at' => optional($tier->created_at)->toISOString(),
            'updated_at' => optional($tier->updated_at)->toISOString(),
        ]);
    }
}
