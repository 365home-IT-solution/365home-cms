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
