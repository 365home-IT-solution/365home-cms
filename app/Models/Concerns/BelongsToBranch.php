<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\AdminPanelContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;

// Áp dụng vào các model dữ liệu Warehouse thực sự khác nhau THEO TỪNG CHI NHÁNH (vật tư, phiếu
// nhập/xuất/kiểm kê — KHÔNG áp dụng cho Nhóm vật tư/Đơn vị tính, 2 danh mục đó dùng CHUNG cho cả
// đối tác). "Chi nhánh" = Category gốc (parent_id null, category_type 'product').
//
// 1 tài khoản có thể quản lý NHIỀU chi nhánh (không chỉ 1) — dùng lại
// User::rootProductCategoryIds() làm nguồn xác định "tài khoản này được thấy/tạo cho những chi
// nhánh nào" (đã tái sử dụng nguyên vẹn: chủ đối tác không giới hạn trong PHẠM VI ĐỐI TÁC của họ;
// nhân viên bị thu hẹp thêm theo UserBranchPermission nếu có gán cụ thể — cùng 1 nguồn duy nhất
// với phần còn lại của hệ thống, xem app/Models/User.php). Super_admin luôn xem/tạo được cho MỌI
// chi nhánh (tự chọn qua field trên form).
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder) {
            // Cùng lý do như BelongsToPartner — scope chi nhánh chỉ có nghĩa trong admin panel.
            if (! AdminPanelContext::isActive()) {
                return;
            }

            $user = auth()->user();

            if (! $user instanceof User) {
                return;
            }

            // effectiveBranchIds() = rootProductCategoryIds() (mọi chi nhánh được phép, kể cả
            // super_admin) trừ khi user đang thu hẹp qua nút "Chuyển đổi chi nhánh" — nhờ vậy
            // super_admin mặc định vẫn thấy hết như trước, chỉ bị lọc khi chủ động chọn.
            $branchIds = $user->effectiveBranchIds();

            // Không xác định được chi nhánh nào (vd tài khoản chưa gán đối tác) — không lọc thêm
            // để tránh vô tình ẩn hết dữ liệu; scope partner_id (BelongsToPartner) vẫn áp dụng
            // song song.
            if (empty($branchIds)) {
                return;
            }

            $builder->whereIn($builder->getModel()->getTable() . '.branch_id', $branchIds);
        });

        static::creating(function ($model) {
            if (! empty($model->branch_id) || ! AdminPanelContext::isActive()) {
                return;
            }

            $user = auth()->user();
            if (! $user instanceof User) {
                return;
            }

            // Chỉ tự gán khi tài khoản CHỈ quản lý (hoặc đang thu hẹp còn) ĐÚNG 1 chi nhánh — nếu
            // quản lý nhiều hơn 1, bắt buộc phải tự chọn qua field trên form (xem
            // WarehouseItemForm::branchInput() và tương tự ở 3 form phiếu), không đoán bừa 1 trong
            // nhiều chi nhánh.
            $branchIds = $user->effectiveBranchIds();
            if (count($branchIds) === 1) {
                $model->branch_id = $branchIds[0];
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'branch_id');
    }
}
