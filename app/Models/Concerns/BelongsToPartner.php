<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Áp dụng vào các model dữ liệu nghiệp vụ thuộc riêng từng đối tác (products, orders, coupons,
// promotions, access_codes, ttlock_accounts, audit_logs, employees...). Super_admin luôn xem được
// toàn bộ; các user khác chỉ thấy dữ liệu của đối tác mình (users.partner_id).
trait BelongsToPartner
{
    protected static function bootBelongsToPartner(): void
    {
        static::addGlobalScope('partner', function (Builder $builder) {
            $user = auth()->user();

            // Scope lọc theo đối tác CHỈ có nghĩa ở phía admin (nhân viên/đối tác đăng nhập bằng
            // App\Models\User) — các model dùng trait này (Product, Order...) cũng được truy vấn
            // từ API phía khách hàng (Customer, cùng dùng Sanctum nên auth()->user() vẫn trả về
            // giá trị chứ không phải null). Customer KHÔNG có khái niệm "thuộc đối tác nào" (khách
            // xem/đặt được TẤT CẢ đối tác) — trước đây code gọi thẳng $user->isSuperAdmin() ở đây
            // gây crash 500 khi $user là Customer (BadMethodCallException); nếu chỉ sửa cho hết
            // crash mà không thêm điều kiện instanceof, kết quả còn tệ hơn: $user->partner_id trên
            // Customer trả về null, khiến câu WHERE partner_id = NULL không khớp dòng nào → khách
            // hàng thấy TRỐNG TRƠN (wishlist, sản phẩm...) một cách âm thầm, không báo lỗi.
            if (! $user instanceof User || $user->isSuperAdmin()) {
                return;
            }

            $builder->where($builder->getModel()->getTable() . '.partner_id', $user->partner_id);
        });

        static::creating(function ($model) {
            // Cùng lý do như global scope ở trên — chỉ tự gán partner_id theo NGƯỜI ĐANG ĐĂNG NHẬP
            // khi đó là User (admin/nhân viên/đối tác). Nếu là Customer (đặt phòng qua API khách
            // hàng), partner_id của đơn phải xác định theo NƠI khách đặt (category/chi nhánh), do
            // chỗ khác trong luồng đặt phòng gán — không gán nhầm theo Customer (vốn không có khái
            // niệm partner_id, trước đây sẽ luôn ra null một cách âm thầm ở đây).
            if (empty($model->partner_id) && auth()->user() instanceof User) {
                $model->partner_id = auth()->user()->partner_id;
            }
        });
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
