<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\AdminPanelContext;
use Illuminate\Database\Eloquent\Builder;

// Áp dụng cho model gắn chi nhánh qua Categorizable (morphToMany bảng categorizables) thay vì cột
// branch_id trực tiếp — hiện dùng cho Product và Coupon. Cùng tinh thần với BelongsToBranch: lọc
// theo User::effectiveBranchIds() (mặc định toàn bộ chi nhánh được phép, thu hẹp khi user chọn
// "Chuyển đổi chi nhánh"), chỉ áp dụng trong admin panel, cộng thêm (AND) với scope BelongsToPartner
// đã có sẵn trên các model này.
trait BelongsToActiveBranchCategories
{
    protected static function bootBelongsToActiveBranchCategories(): void
    {
        static::addGlobalScope('active_branch_category', function (Builder $builder) {
            if (! AdminPanelContext::isActive()) {
                return;
            }

            $user = auth()->user();

            if (! $user instanceof User) {
                return;
            }

            // super_admin MẶC ĐỊNH xem MỌI chi nhánh — NHƯNG khi chủ động thu hẹp qua nút "Chuyển
            // đổi chi nhánh" ở header, phải tôn trọng lựa chọn đó thay vì bypass vô điều kiện (bug
            // đã xác nhận thực tế — xem giải thích chi tiết ở BelongsToBranch, cùng khung điều kiện).
            // Dùng thẳng session để tránh rủi ro effectiveBranchIds()/rootProductCategoryIds() thiếu
            // taxonomy khi bộ lọc đang thực sự áp dụng.
            if ($user->isSuperAdmin()) {
                $selectedBranchIds = session('active_branch_ids');

                if (empty($selectedBranchIds)) {
                    return;
                }

                $categoryIds = $user->visibleProductCategoryIds($selectedBranchIds);

                if (empty($categoryIds)) {
                    return;
                }

                $builder->whereHas(
                    'categories',
                    fn ($query) => $query->whereIn('categories.id', $categoryIds)
                );

                return;
            }

            $branchIds = $user->effectiveBranchIds();

            if (empty($branchIds)) {
                return;
            }

            $categoryIds = $user->visibleProductCategoryIds($branchIds);

            if (empty($categoryIds)) {
                return;
            }

            $builder->whereHas(
                'categories',
                fn ($query) => $query->whereIn('categories.id', $categoryIds)
            );
        });
    }
}
