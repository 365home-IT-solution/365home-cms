<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Partner;
use App\Models\User;
use App\Support\AdminPanelContext;
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
            // Scope lọc theo đối tác CHỈ có nghĩa (và chỉ được PHÉP áp dụng) bên trong admin panel
            // Filament. Trang/API phía khách hàng (booking board /branch/{slug}, Livewire Book...)
            // chạy qua middleware group 'web' nên VẪN giữ session — nếu cùng trình duyệt có một
            // nhân viên/đối tác đang đăng nhập CMS (guard 'web'), auth()->user() ở trang client vẫn
            // trả về User đó dù request không liên quan gì tới panel admin. Trước đây scope chỉ dựa
            // vào "có User đăng nhập hay không" nên vô tình lọc luôn lịch đặt phòng của khách hàng
            // theo partner_id của nhân viên đang có session, dù khách đang xem đúng chi nhánh theo
            // URL slug. KHÔNG dùng Filament::getCurrentPanel() ở đây: panel 'admin' được đăng ký
            // ->default() nên FilamentManager tự gán currentPanel = panel admin ngay khi service
            // 'filament' được resolve lần đầu trong BẤT KỲ request nào — đã kiểm chứng thực tế nó
            // vẫn trả về 'admin' trên một request tới trang client không liên quan gì tới Filament.
            // AdminPanelContext::isActive() chỉ true khi middleware MarkAdminPanelContext thực sự
            // chạy (hoặc được Livewire persistent-middleware replay đúng cách) trên route panel admin.
            if (! AdminPanelContext::isActive()) {
                return;
            }

            $user = auth()->user();

            // Customer KHÔNG có khái niệm "thuộc đối tác nào" (khách xem/đặt được TẤT CẢ đối tác) —
            // trước đây code gọi thẳng $user->isSuperAdmin() ở đây gây crash 500 khi $user là
            // Customer (BadMethodCallException); nếu chỉ sửa cho hết crash mà không thêm điều kiện
            // instanceof, kết quả còn tệ hơn: $user->partner_id trên Customer trả về null, khiến câu
            // WHERE partner_id = NULL không khớp dòng nào → khách hàng thấy TRỐNG TRƠN (wishlist,
            // sản phẩm...) một cách âm thầm, không báo lỗi.
            if (! $user instanceof User || $user->isSuperAdmin()) {
                return;
            }

            $builder->where($builder->getModel()->getTable() . '.partner_id', $user->partner_id);
        });

        static::creating(function ($model) {
            // Cùng lý do như global scope ở trên — chỉ tự gán partner_id theo NGƯỜI ĐANG ĐĂNG NHẬP
            // khi bản ghi đang được tạo TRONG admin panel. Nếu là Customer đặt phòng qua API khách
            // hàng (hoặc Livewire Book ở trang client), partner_id của đơn phải xác định theo NƠI
            // khách đặt (category/chi nhánh), do chỗ khác trong luồng đặt phòng gán — không gán nhầm
            // theo session admin đang mở cùng trình duyệt.
            if (empty($model->partner_id) && AdminPanelContext::isActive() && auth()->user() instanceof User) {
                $model->partner_id = auth()->user()->partner_id;
            }
        });
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
