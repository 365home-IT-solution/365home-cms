<?php

namespace Modules\Minihouse\App\Services;

use App\Services\FcmService;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;

// Nơi DUY NHẤT tạo dòng thông báo trong Portal khách thuê — mọi nguồn (hoá đơn mới, nhắc việc đến
// hạn, thông báo chung, chat, phản hồi được xử lý) đều PHẢI gọi qua đây, không tự tạo
// PortalNotification::create()/gọi thẳng FcmService rải rác ở nhiều nơi — thiếu bước này thì thông
// báo không được lưu lại, khách lỡ push (app đóng/mất mạng lúc gửi) sẽ mất dấu vĩnh viễn, không có
// cách nào xem lại trong danh sách "Thông báo" của Portal (đúng lỗi thật đã gặp ở
// MinihouseChatService::notifyTenant() gọi thẳng FcmService, sửa lại dùng đúng hàm này).
//
// Gói push MIRROR ĐÚNG cấu trúc dữ liệu của App\Services\NotificationFcmService::sendToCustomer()
// (chat Home) — cùng luôn có notification_id + unread_count trong data, để app di động xử lý y hệt
// nhau cho cả 2 dự án, dù bảng lưu trữ phía sau khác nhau (Home dùng NotificationFcm dùng chung +
// NotificationFcmRecipient theo dõi từng khách — phù hợp cho gửi hàng loạt; MiniHouse dùng 1 dòng
// PortalNotification/khách — đơn giản hơn nhưng cho kết quả CUỐI CÙNG giống hệt với khách: lưu lại,
// xem lại được, đếm đúng số chưa đọc).
class PortalNotificationService
{
    // $pushData: field TUỲ BIẾN — vừa LƯU LẠI vào cột `data` của dòng thông báo (mirror
    // App\Models\NotificationFcm.data của Home, để khách mở lại danh sách "Thông báo" sau này vẫn
    // thấy đủ context, không chỉ lúc nhận push), vừa gửi kèm trong gói push. VD chat cần
    // conversation_id/contract_id/room_code để app biết mở đúng màn nào khi bấm vào thông báo, khác
    // với các nguồn khác (hoá đơn, nhắc việc) chỉ cần link.
    public static function notify(Tenant $tenant, string $type, string $title, ?string $body = null, ?string $link = null, array $pushData = []): PortalNotification
    {
        $notification = PortalNotification::create([
            'tenant_id' => $tenant->id,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'link'      => $link,
            'data'      => $pushData ?: null,
        ]);

        static::pushToTenant($tenant, $title, $body, $link, array_merge($pushData, [
            'notification_id' => (string) $notification->id,
            'type'            => $type,
        ]));

        return $notification;
    }

    // Gửi CÙNG 1 thông báo cho NHIỀU khách thuê KHÔNG liên quan tới cùng 1 hợp đồng (khác
    // notifyContractTenants() ở trên) — dùng cho admin soạn gửi hàng loạt (xem
    // Api\Admin\Minihouse\PushNotificationController). Mỗi khách vẫn là 1 dòng riêng qua notify(),
    // trả về số đã gửi/lỗi để nơi gọi lưu lại thống kê (mirror sent_count/fail_count của Home) — lỗi
    // 1 khách KHÔNG chặn các khách còn lại.
    public static function notifyMany(iterable $tenants, string $type, string $title, ?string $body = null, ?string $link = null): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                static::notify($tenant, $type, $title, $body, $link);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('PortalNotificationService::notifyMany: gửi thất bại cho 1 khách', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    // Gửi CÙNG 1 thông báo cho TOÀN BỘ khách thuê liên quan tới 1 hợp đồng (đứng tên chính LẪN ở
    // cùng) — dùng cho hoá đơn mới, vì mọi người ở cùng phòng đều cần biết, không chỉ người đứng tên.
    public static function notifyContractTenants(int $contractId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $tenants = Tenant::whereIn('id', ContractTenant::where('contract_id', $contractId)->pluck('tenant_id')->unique())->get();

        if ($tenants->isEmpty()) {
            return;
        }

        $now = now();

        PortalNotification::insert(
            $tenants->map(fn (Tenant $tenant) => [
                'tenant_id'  => $tenant->id,
                'type'       => $type,
                'title'      => $title,
                'body'       => $body,
                'link'       => $link,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        static::pushMany($tenants, $title, $body, $link, $type);
    }

    // Dùng khi 1 nơi khác TỰ bulk-insert PortalNotification (không qua notify()/notifyContractTenants()
    // ở trên, VD AnnouncementObserver — insert() thẳng để nhanh với số lượng khách lớn) nhưng vẫn cần
    // kênh Thông báo đẩy đi kèm — tách riêng để không phải viết lại đúng vòng lặp try/catch này lần nữa.
    public static function pushMany(iterable $tenants, string $title, ?string $body, ?string $link, ?string $type = null): void
    {
        foreach ($tenants as $tenant) {
            static::pushToTenant($tenant, $title, $body, $link, $type ? ['type' => $type] : []);
        }
    }

    // Lỗi gửi push KHÔNG được chặn việc tạo PortalNotification (dòng thông báo trong Portal luôn
    // phải có, push chỉ là kênh CỘNG THÊM để khách biết ngay không cần tự mở Portal) — tự bỏ qua nếu
    // khách chưa đăng ký thiết bị nào (FcmService::sendToTenant() tự return sớm khi rỗng).
    private static function pushToTenant(Tenant $tenant, string $title, ?string $body, ?string $link, array $extra = []): void
    {
        try {
            $data = array_merge(
                $extra,
                $link ? ['link' => url($link)] : [],
                // Cùng field 'unread_count' Home đang gửi (App\Services\NotificationFcmService::
                // getUnreadCount()) — tính ĐÚNG lúc gửi (đã tính luôn dòng vừa tạo ở trên), app hiện
                // badge số ngay không cần gọi API đếm riêng.
                ['unread_count' => (string) self::unreadCount($tenant)],
            );

            app(FcmService::class)->sendToTenant($tenant, $title, $body ?: '', $data);
        } catch (\Throwable $e) {
            Log::warning('PortalNotificationService: gửi push thất bại', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private static function unreadCount(Tenant $tenant): int
    {
        return PortalNotification::where('tenant_id', $tenant->id)->whereNull('read_at')->count();
    }
}
