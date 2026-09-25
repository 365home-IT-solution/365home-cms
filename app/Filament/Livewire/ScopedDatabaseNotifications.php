<?php

declare(strict_types=1);

namespace App\Filament\Livewire;

use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

// Ghi đè chuông thông báo mặc định của Filament (đăng ký lại dưới ĐÚNG tên "filament.livewire.
// database-notifications" trong AppServiceProvider::boot() — cùng cơ chế Breezy personal_info/
// update_password đã dùng, vì Filament::databaseNotifications() luôn gắn cứng class gốc, không có
// tham số panel nào để tự đổi) — bug thật đã gặp: 2 panel Home ("admin") và MiniHouse
// ("minihouse-admin") dùng CHUNG bảng `notifications` (cùng 1 User model, notifications gắn theo
// user chứ không theo panel), nên 1 tài khoản có quyền cả 2 panel (VD super_admin) sẽ thấy LẪN thông
// báo của module kia trên chuông — đã tự xác nhận qua báo cáo thật: thử hợp đồng ở MiniHouse lại nổi
// chuông bên panel Home. Lọc theo cờ `module` gắn trong viewData lúc gửi (xem
// App\Services\AdminNotificationService::notify() — MỌI notify() gọi từ code MiniHouse PHẢI truyền
// $data['module'] = 'minihouse', không thì mặc định coi là của Home/dùng chung).
class ScopedDatabaseNotifications extends DatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        $query   = parent::getNotificationsQuery();
        $panelId = Filament::getCurrentPanel()?->getId();

        if ($panelId === 'minihouse-admin') {
            return $query->where('data->viewData->module', 'minihouse');
        }

        // Mọi panel khác (Home "admin", và bất kỳ panel nào khác sau này) — loại thông báo gắn cờ
        // module=minihouse. whereNull() giữ nguyên toàn bộ thông báo cũ/thông báo Home hiện có (chưa
        // từng có key "module" trong data) — chỉ LOẠI đúng những cái tự khai module=minihouse.
        return $query->where(function (Builder $q) {
            $q->whereNull('data->viewData->module')
                ->orWhere('data->viewData->module', '!=', 'minihouse');
        });
    }
}
