<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MembershipTier;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quản lý HẠNG THÀNH VIÊN (bảng `membership_tiers`) — CRUD đầy đủ, cộng thêm 2 khu vực cấu hình
 * voucher/hạng + endpoint đồng bộ. Field khớp App\Filament\Resources\MembershipTierResource — 2 nơi
 * này dùng CHUNG logic ở App\Services\MembershipService (voucherTemplatesToFormState/
 * syncVoucherTemplates/manualCouponIdsToFormState/syncManualCoupons/syncAutoVouchersForTierMembers)
 * để tránh lặp code và đảm bảo hành vi giống hệt nhau dù thao tác từ Filament hay từ API.
 *
 * Hạng KHÔNG thuộc riêng đối tác nào (dùng chung toàn hệ thống, giống Customer) — không lọc theo
 * partner_id.
 *
 * Luồng cấp voucher tự động khi khách đạt hạng (xem App\Services\MembershipService):
 *  - welcome_coupon_* : cơ chế CŨ, cấu hình 1 voucher DUY NHẤT/hạng — ĐÃ NGƯNG DÙNG (field vẫn còn
 *    trong DB/API để không phá API cũ, nhưng KHÔNG dùng cho hạng nào nữa — mọi hạng hiện tại đã
 *    reset welcome_coupon_value=0 để tránh cấp trùng với voucher_templates bên dưới). Đừng set lại
 *    field này khi tạo/sửa hạng.
 *  - voucher_templates[] (POST .../voucher-templates hoặc gửi kèm trong store/update) : cấu hình
 *    N voucher CHÍNH THỨC của hạng (mỗi phần tử = 1 voucher: prefix, type, value, min_order_value?,
 *    validity_days, usage_limit?, is_exclusive?, category_ids?) — hệ thống TỰ TẠO coupon mẫu (nguồn
 *    pivot 'auto'), không cần tạo coupon trước ở /api/admin/coupons. Đây là luồng CHÍNH THỨC — dùng
 *    cho toàn bộ voucher hạng cấp cho MỌI khách khi lên hạng. 'category_ids' (mảng id chi nhánh, xem
 *    GET /api/admin/branches) giới hạn voucher chỉ dùng được ở các chi nhánh đó (gồm khu vực con) —
 *    bỏ trống/không gửi = áp dụng mọi chi nhánh (Coupon::passesBranchRestriction()).
 *  - coupon_ids[] (gửi kèm trong store/update) : gắn TAY N coupon CÓ SẴN (tạo trước ở
 *    /api/admin/coupons) làm mẫu cho hạng (nguồn pivot 'manual') — dùng cho trường hợp NGOẠI
 *    LỆ/cứu cháy, KHÔNG dùng cho voucher chính thức (đã có voucher_templates ở trên). 2 danh sách
 *    này lưu tách biệt (cột pivot membership_tier_coupon.source) — sửa danh sách này không ảnh
 *    hưởng danh sách kia.
 *  - Coupon mẫu (dù từ voucher_templates hay coupon_ids) có 'validity_days' sẽ được nhân bản thành
 *    1 bản sao RIÊNG cho từng khách (hạn = lúc lên hạng + validity_days); mẫu KHÔNG có validity_days
 *    thì mọi khách trong hạng dùng CHUNG 1 coupon với hạn cố định (start_at/end_at).
 *  - POST .../{id}/sync-vouchers : rà lại khách ĐANG giữ hạng, cấp bù voucher_templates (nguồn
 *    'auto') nào khách đang thiếu so với cấu hình hiện tại — dùng khi cấu hình hạng thay đổi SAU
 *    khi khách đã lên hạng từ trước (dữ liệu cũ), hoặc muốn backfill mà không cần sửa lại hạng.
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

        $this->syncVoucherFields($tier, $data);

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

        $this->syncVoucherFields($tier, $data);

        return response()->json(['data' => $this->toDetailItem($tier->fresh(['coupons']))]);
    }

    /**
     * POST /api/admin/membership-tiers/{id}/sync-vouchers
     * Nút "Đồng bộ" (giống hệt Filament EditMembershipTier) — rà lại khách ĐANG giữ hạng này, cấp
     * bù voucher nào (nguồn 'auto' — theo đúng cấu hình 'voucher_templates' hiện tại) khách đang
     * thiếu. Không đụng tới mã gắn tay ('coupon_ids'/nguồn 'manual'). An toàn gọi lại nhiều lần —
     * không cấp trùng cho khách đã có sẵn.
     */
    public function syncVouchers(int $id): JsonResponse
    {
        $tier = MembershipTier::find($id);

        if (! $tier) {
            return response()->json(['message' => 'Không tìm thấy hạng thành viên.'], 404);
        }

        $result = app(MembershipService::class)->syncAutoVouchersForTierMembers($tier);

        return response()->json(['data' => $result]);
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

    /**
     * Đồng bộ 'voucher_templates' (nguồn 'auto') + 'coupon_ids' (nguồn 'manual') nếu có mặt trong
     * $data — dùng chung cho store()/update(). Không truyền field nào = giữ nguyên cấu hình hiện
     * tại của khu vực đó; truyền [] = gỡ hết. 2 khu vực lưu tách biệt, không ghi đè lẫn nhau (xem
     * docblock class).
     */
    private function syncVoucherFields(MembershipTier $tier, array $data): void
    {
        $service = app(MembershipService::class);
        $touched = false;

        if (array_key_exists('voucher_templates', $data)) {
            $service->syncVoucherTemplates($tier, $data['voucher_templates'] ?? []);
            $touched = true;
        }

        if (array_key_exists('coupon_ids', $data)) {
            $service->syncManualCoupons($tier, $data['coupon_ids'] ?? []);
            $touched = true;
        }

        if ($touched) {
            $service->distributeTierCoupons($tier);
        }
    }

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

            // Cơ chế CŨ, đã ngưng dùng (xem docblock class) — giữ lại field để không phá API cũ
            // nhưng KHÔNG nên gửi welcome_coupon_value > 0 nữa, sẽ cấp trùng với voucher_templates.
            'welcome_coupon_prefix'      => 'nullable|string|max:20',
            'welcome_coupon_type'        => 'nullable|in:percentage,fixed',
            'welcome_coupon_value'       => 'nullable|numeric|min:0',
            'welcome_coupon_days'        => 'nullable|integer|min:0',
            'welcome_coupon_usage_limit' => 'nullable|integer|min:1',

            // Voucher CHÍNH THỨC của hạng (nguồn pivot 'auto') — mỗi phần tử = 1 voucher, hệ thống
            // tự tạo coupon mẫu, không cần tạo trước ở /api/admin/coupons. 'template_id' chỉ cần
            // truyền khi SỬA 1 voucher đã có (lấy từ GET .../{id} → voucher_templates[].template_id)
            // — bỏ trống = tạo voucher mới. Không truyền field 'voucher_templates' = giữ nguyên toàn
            // bộ danh sách hiện tại; truyền [] = gỡ hết voucher chính thức của hạng.
            'voucher_templates'                    => 'sometimes|array',
            'voucher_templates.*.template_id'      => 'nullable|integer|exists:coupons,id',
            'voucher_templates.*.prefix'           => 'required_with:voucher_templates|string|max:20',
            'voucher_templates.*.type'             => 'required_with:voucher_templates|in:percentage,fixed',
            'voucher_templates.*.value'            => 'required_with:voucher_templates|numeric|min:0',
            'voucher_templates.*.min_order_value'  => 'nullable|numeric|min:0',
            'voucher_templates.*.validity_days'    => 'required_with:voucher_templates|integer|min:1|max:365',
            'voucher_templates.*.usage_limit'      => 'nullable|integer|min:1',
            'voucher_templates.*.is_exclusive'     => 'nullable|boolean',
            // Chi nhánh được phép dùng voucher này — bỏ trống/không gửi = áp dụng mọi chi nhánh
            // (xem Coupon::passesBranchRestriction()).
            'voucher_templates.*.category_ids'     => 'nullable|array',
            'voucher_templates.*.category_ids.*'   => 'integer|exists:categories,id',

            // Danh sách id coupon có sẵn (tạo ở /api/admin/coupons) gắn TAY vào hạng (nguồn pivot
            // 'manual') — dùng cho trường hợp ngoại lệ/cứu cháy, KHÔNG dùng cho voucher chính thức
            // (xem voucher_templates ở trên). Không truyền field này = giữ nguyên; truyền [] = gỡ hết.
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
        $tier->loadMissing('coupons');
        $service = app(MembershipService::class);

        return array_merge($this->toListItem($tier), [
            'description'                => $tier->description,
            'welcome_coupon_prefix'      => $tier->welcome_coupon_prefix,
            'welcome_coupon_type'        => $tier->welcome_coupon_type,
            'welcome_coupon_value'       => $tier->welcome_coupon_value,
            'welcome_coupon_days'        => $tier->welcome_coupon_days,
            'welcome_coupon_usage_limit' => $tier->welcome_coupon_usage_limit,
            // Voucher CHÍNH THỨC của hạng (nguồn 'auto') — shape khớp với body 'voucher_templates'
            // nhận ở store()/update(), sửa 1 voucher thì gửi lại đúng 'template_id' của nó.
            'voucher_templates'          => $service->voucherTemplatesToFormState($tier),
            // Mã gắn tay (nguồn 'manual', xem docblock class) — chỉ id, khớp body 'coupon_ids'.
            'manual_coupon_ids'          => $service->manualCouponIdsToFormState($tier),
            'manual_coupons'             => $tier->coupons->where('pivot.source', 'manual')->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                'type' => $c->type, 'value' => $c->value,
                'min_order_value' => $c->min_order_value, 'validity_days' => $c->validity_days,
            ])->values(),
            'created_at' => optional($tier->created_at)->toISOString(),
            'updated_at' => optional($tier->updated_at)->toISOString(),
        ]);
    }
}
