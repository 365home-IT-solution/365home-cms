<?php

namespace Modules\Minihouse\App\Services;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Support\MinihousePermissions;
use Modules\Minihouse\App\Support\ReminderRecipientResolver;

// Gửi thông báo khi 1 Reminder đến hạn — 3 KÊNH ĐỘC LẬP, kênh này lỗi không chặn kênh kia:
//  1. Chuông + bảng "notifications" dùng chung với Home (App\Services\AdminNotificationService) cho
//     NHÂN VIÊN/chủ nhà — ai nhận xem recipientsFor().
//  2. Zalo ZNS (MinihouseZaloService, tài khoản Zalo OA RIÊNG của MiniHouse — KHÔNG đụng Zalo OA của
//     Home) cho ĐÚNG KHÁCH THUÊ liên quan (nhắc đóng tiền/hết hạn hợp đồng gửi khách đứng tên hợp
//     đồng, nhắc bảo trì gửi khách đang ở phòng đó) — tự bỏ qua nếu chưa cấu hình Zalo/chưa có mẫu
//     ZNS cho loại đó/reminder không có khách cụ thể, không throw ra ngoài.
//  3. SMS Brandname (MinihouseSmsService, eSMS.vn) — CÙNG đối tượng nhận với Zalo (khách thuê liên
//     quan), gửi SONG SONG chứ không phải dự phòng khi Zalo lỗi — khách có thể chỉ dùng 1 trong 2
//     kênh liên lạc, gửi cả 2 tăng khả năng khách thực sự nhận được nhắc việc. Tự bỏ qua nếu chưa
//     cấu hình SMS, không throw ra ngoài.
class ReminderNotificationService
{
    // Đã giao cho ĐÚNG 1 nhân viên (Reminder::assigned_to, chủ yếu "Nhắc bảo trì") thì chỉ báo riêng
    // người đó — báo chuông cho CẢ nhóm nhân viên toà nhà như trước sẽ làm loãng trách nhiệm đã giao
    // rõ ràng. Chưa giao cho ai thì giữ hành vi cũ (báo chung nhân viên phụ trách đúng toà nhà).
    public static function recipientsFor(Reminder $reminder): Collection
    {
        if ($reminder->assigned_to) {
            $assignee = MinihousePermissions::scopeToMinihouseUsers(User::query())
                ->whereKey($reminder->assigned_to)
                ->get();

            if ($assignee->isNotEmpty()) {
                return $assignee;
            }
        }

        return static::recipientsForBuilding($reminder->resolveBuildingId());
    }

    // Tách riêng theo building_id (không gắn cứng vào Reminder) — dùng lại được cho các luồng thông
    // báo nội bộ khác cùng nhu cầu "nhân viên phụ trách đúng toà nhà này" (VD nhắc hạn Khai báo lưu
    // trú, xem ResidenceDeclarationService::notifyDue()).
    public static function recipientsForBuilding(?int $buildingId): Collection
    {
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

        if ($recipients->isNotEmpty()) {
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
                // context nào đang active → "Route [...] not defined." Ghép thẳng path panel đã
                // biết trước (xem MinihouseAdminPanelProvider::path('minihouse-admin')).
                url('/minihouse-admin/reminders/' . $reminder->id . '/edit'),
            );
        }

        // Kênh Zalo ĐỘC LẬP với kênh chuông ở trên — vẫn thử gửi dù danh sách nhân viên nhận chuông
        // rỗng (2 đối tượng khác nhau: nhân viên vs khách thuê). Tự bỏ qua bên trong nếu chưa cấu
        // hình/không có khách cụ thể — không throw, không được để lỗi Zalo chặn mất
        // updateQuietly(notified_at) bên dưới.
        try {
            app(MinihouseZaloService::class)->sendReminderNotification($reminder);
        } catch (\Throwable $e) {
            Log::warning('ReminderNotificationService: gửi Zalo thất bại', [
                'reminder_id' => $reminder->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Kênh SMS — ĐỘC LẬP với Zalo (gửi song song, không phải dự phòng), xem MinihouseSmsService.
        try {
            app(MinihouseSmsService::class)->sendReminderNotification($reminder);
        } catch (\Throwable $e) {
            Log::warning('ReminderNotificationService: gửi SMS thất bại', [
                'reminder_id' => $reminder->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // Kênh 4: Portal khách thuê — CÙNG đối tượng nhận với Zalo/SMS (ReminderRecipientResolver),
        // để khách thấy lại đúng nhắc việc đó trong Portal dù có nhận được Zalo/SMS hay không (VD
        // chưa cấu hình Zalo/SMS thì Portal vẫn là nơi khách biết được).
        try {
            $tenant = ReminderRecipientResolver::resolve($reminder);

            if ($tenant) {
                PortalNotificationService::notify(
                    $tenant,
                    PortalNotification::TYPE_REMINDER,
                    $reminder->title,
                    $reminder->content ?: ('Đến hạn: ' . $reminder->remind_date?->format('d/m/Y')),
                    $reminder->invoice_id ? '/minihouse/portal/invoices/' . $reminder->invoice_id : null,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ReminderNotificationService: tạo thông báo Portal thất bại', [
                'reminder_id' => $reminder->id,
                'error'       => $e->getMessage(),
            ]);
        }

        // updateQuietly() — tránh tự kích hoạt lại observer/sự kiện khác (Reminder hiện chưa có
        // observer nào, nhưng giữ quy ước an toàn chung của cả module).
        $reminder->updateQuietly(['notified_at' => now()]);
    }
}
