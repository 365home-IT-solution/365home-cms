<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Settings\VoucherScopeSettings;
use App\Support\VoucherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;

/**
 * Phạm vi đối tác / chi nhánh cho voucher hệ thống tự tặng (chỉ Super Admin) — cùng dữ liệu với Filament
 * "Phạm vi voucher tự tặng" (App\Filament\Pages\Setting\ManageVoucherScopeSettings). Xem App\Support\VoucherScope.
 *
 *  - GET|PUT /api/admin/coupons/scope-settings   xem/sửa phạm vi cho 2 nhóm: checkin (mã điểm danh) và
 *    minigame (thẻ cào, sưu tầm mảnh ghép, giới thiệu bạn bè, đổi điểm game, Caro — giải Caro có chi nhánh riêng thì theo giải)
 *
 * Mỗi nhóm có *_partner_ids (mọi chi nhánh của các đối tác này) và *_category_ids (thu hẹp về đúng các chi
 * nhánh/khu vực con này). Chỉ áp cho mã tặng từ lúc lưu — mã đã tặng trước đó giữ nguyên. Chưa cấu hình
 * đối tác/chi nhánh nào thì mã vẫn được tặng nhưng dùng được ở MỌI đối tác.
 */
class VoucherScopeSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json(['data' => $this->payload(app(VoucherScopeSettings::class))]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'checkin_partner_ids'    => 'sometimes|array',
            'checkin_partner_ids.*'  => 'string',
            'checkin_category_ids'   => 'sometimes|array',
            'checkin_category_ids.*' => 'integer',

            'minigame_partner_ids'    => 'sometimes|array',
            'minigame_partner_ids.*'  => 'string',
            'minigame_category_ids'   => 'sometimes|array',
            'minigame_category_ids.*' => 'integer',
        ]);

        $settings = app(VoucherScopeSettings::class);

        foreach ([VoucherScope::CHECKIN, VoucherScope::MINIGAME] as $scope) {
            if (array_key_exists("{$scope}_partner_ids", $data)) {
                $settings->{"{$scope}_partner_ids"} = array_values(array_map('strval', $data["{$scope}_partner_ids"]));
            }
            if (array_key_exists("{$scope}_category_ids", $data)) {
                $settings->{"{$scope}_category_ids"} = array_values(array_map('intval', $data["{$scope}_category_ids"]));
            }
        }

        $settings->save();
        app()->forgetInstance(VoucherScopeSettings::class);

        return response()->json(['message' => 'Đã lưu phạm vi voucher tự tặng.', 'data' => $this->payload(app(VoucherScopeSettings::class))]);
    }

    private function payload(VoucherScopeSettings $settings): array
    {
        $partnerIds = array_unique(array_merge($settings->checkin_partner_ids, $settings->minigame_partner_ids));
        $partners   = Partner::query()->withoutGlobalScopes()->whereIn('id', $partnerIds)->pluck('name', 'id');

        $categoryIds = array_unique(array_merge($settings->checkin_category_ids, $settings->minigame_category_ids));
        $categories  = Category::query()->withoutGlobalScopes()->whereIn('id', $categoryIds)->pluck('name', 'id');

        $section = fn (array $partnerIds, array $categoryIds) => [
            'partner_ids'   => $partnerIds,
            'partners'      => collect($partnerIds)->map(fn ($id) => ['id' => $id, 'name' => $partners[$id] ?? null])->values(),
            'category_ids'  => $categoryIds,
            'categories'    => collect($categoryIds)->map(fn ($id) => ['id' => $id, 'name' => $categories[$id] ?? null])->values(),
            'is_configured' => $partnerIds !== [] || $categoryIds !== [],
        ];

        return [
            'checkin'  => $section($settings->checkin_partner_ids, $settings->checkin_category_ids),
            'minigame' => $section($settings->minigame_partner_ids, $settings->minigame_category_ids),
        ];
    }

    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
