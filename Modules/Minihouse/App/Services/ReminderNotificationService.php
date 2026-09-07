<?php

namespace Modules\Minihouse\App\Services;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Support\Collection;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Support\MinihousePermissions;

// Gửi thông báo (bảng "notifications" dùng chung + chuông Filament — xem
// App\Services\AdminNotificationService, tái sử dụng NGUYÊN VẸN cơ chế đang có của Home, không viết
// lại) khi 1 Reminder đến hạn. Ai nhận: super_admin + tài khoản MiniHouse được gán ĐÚNG toà nhà của
// reminder + tài khoản MiniHouse CHƯA bị giới hạn toà nào (mặc định mở, xem User::rootBuildingIds())
// — reminder không gắn phòng/hợp đồng nào (VD "Đóng thuế quý") thì gửi cho MỌI tài khoản MiniHouse.
class ReminderNotificationService
{
    public static function recipientsFor(Reminder $reminder): Collection
    {
        $buildingId = $reminder->room?->building_id ?? $reminder->contract?->room?->building_id;

        $query = MinihousePermissions::scopeToMinihouseUsers(User::query());

        if ($buildingId) {
            $query->where(function ($q) use ($buildingId) {
                $q->whereHas('roles', fn ($q2) => $q2->where('name', config('filament-shield.super_admin.name')))
                    ->orWhereDoesntHave('minihouseBuildings')
                    ->orWhereHas('minihouseBuildings', fn ($q2) => $q2->where('minihouse_buildings.id', $buildingId));
            });
        }

        return $query->get();
    }

    public static function notify(Reminder $reminder): void
    {
        $recipients = static::recipientsFor($reminder);

        if ($recipients->isEmpty()) {
            return;
        }

        $icon = match ($reminder->type) {
            Reminder::TYPE_PAYMENT     => 'heroicon-o-banknotes',
            Reminder::TYPE_CONTRACT    => 'heroicon-o-document-text',
            Reminder::TYPE_MAINTENANCE => 'heroicon-o-wrench-screwdriver',
            default                    => 'heroicon-o-bell-alert',
        };

        app(AdminNotificationService::class)->notify(
            $recipients,
            'Nhắc việc: ' . $reminder->title,
            $reminder->content ?: ('Đến hạn: ' . $reminder->remind_date?->format('d/m/Y')),
            ['type' => 'minihouse_reminder', 'reminder_id' => $reminder->id],
            $icon,
            'warning',
            // KHÔNG dùng ReminderResource::getUrl() — cần Filament::getCurrentPanel() để suy ra
            // đúng route, nhưng lệnh này thường chạy từ console (cron/artisan), không có panel
            // context nào đang active → "Route [...] not defined." Ghép thẳng path panel đã biết
            // trước (xem MinihouseAdminPanelProvider::path('minihouse-admin')).
            url('/minihouse-admin/reminders/' . $reminder->id . '/edit'),
        );

        // updateQuietly() — tránh tự kích hoạt lại observer/sự kiện khác (Reminder hiện chưa có
        // observer nào, nhưng giữ quy ước an toàn chung của cả module).
        $reminder->updateQuietly(['notified_at' => now()]);
    }
}
