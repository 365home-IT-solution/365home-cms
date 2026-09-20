<?php

namespace Modules\Minihouse\App\Services;

use App\Services\FcmService;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;

// Nơi DUY NHẤT tạo dòng thông báo trong Portal khách thuê — mọi nguồn (hoá đơn mới, nhắc việc đến
// hạn, thông báo chung, phản hồi được xử lý) đều gọi qua đây, không tự tạo PortalNotification::create()
// rải rác ở nhiều nơi. Vì đây là điểm hội tụ DUY NHẤT, gắn luôn kênh Thông báo đẩy (Web
// Push/FCM/Expo, xem FcmService::sendToTenant()) tại đây thay vì gắn riêng ở từng nơi gọi — thêm 1
// nguồn thông báo portal mới trong tương lai sẽ tự động có push đi kèm, không cần nhớ gắn thêm.
class PortalNotificationService
{
    public static function notify(Tenant $tenant, string $type, string $title, ?string $body = null, ?string $link = null): PortalNotification
    {
        $notification = PortalNotification::create([
            'tenant_id' => $tenant->id,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'link'      => $link,
        ]);

        static::pushToTenant($tenant, $title, $body, $link);

        return $notification;
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

        static::pushMany($tenants, $title, $body, $link);
    }

    // Dùng khi 1 nơi khác TỰ bulk-insert PortalNotification (không qua notify()/notifyContractTenants()
    // ở trên, VD AnnouncementObserver — insert() thẳng để nhanh với số lượng khách lớn) nhưng vẫn cần
    // kênh Thông báo đẩy đi kèm — tách riêng để không phải viết lại đúng vòng lặp try/catch này lần nữa.
    public static function pushMany(iterable $tenants, string $title, ?string $body, ?string $link): void
    {
        foreach ($tenants as $tenant) {
            static::pushToTenant($tenant, $title, $body, $link);
        }
    }

    // Lỗi gửi push KHÔNG được chặn việc tạo PortalNotification (dòng thông báo trong Portal luôn
    // phải có, push chỉ là kênh CỘNG THÊM để khách biết ngay không cần tự mở Portal) — tự bỏ qua nếu
    // khách chưa đăng ký thiết bị nào (FcmService::sendToTenant() tự return sớm khi rỗng).
    private static function pushToTenant(Tenant $tenant, string $title, ?string $body, ?string $link): void
    {
        try {
            app(FcmService::class)->sendToTenant($tenant, $title, $body ?: '', $link ? ['link' => url($link)] : []);
        } catch (\Throwable $e) {
            Log::warning('PortalNotificationService: gửi push thất bại', [
                'tenant_id' => $tenant->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
