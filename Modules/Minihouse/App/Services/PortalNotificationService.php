<?php

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;

// Nơi DUY NHẤT tạo dòng thông báo trong Portal khách thuê — mọi nguồn (hoá đơn mới, nhắc việc đến
// hạn, thông báo chung, phản hồi được xử lý) đều gọi qua đây, không tự tạo PortalNotification::create()
// rải rác ở nhiều nơi.
class PortalNotificationService
{
    public static function notify(Tenant $tenant, string $type, string $title, ?string $body = null, ?string $link = null): PortalNotification
    {
        return PortalNotification::create([
            'tenant_id' => $tenant->id,
            'type'      => $type,
            'title'     => $title,
            'body'      => $body,
            'link'      => $link,
        ]);
    }

    // Gửi CÙNG 1 thông báo cho TOÀN BỘ khách thuê liên quan tới 1 hợp đồng (đứng tên chính LẪN ở
    // cùng) — dùng cho hoá đơn mới, vì mọi người ở cùng phòng đều cần biết, không chỉ người đứng tên.
    public static function notifyContractTenants(int $contractId, string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $tenantIds = ContractTenant::where('contract_id', $contractId)->pluck('tenant_id')->unique();

        if ($tenantIds->isEmpty()) {
            return;
        }

        $now = now();

        PortalNotification::insert(
            $tenantIds->map(fn (int $tenantId) => [
                'tenant_id'  => $tenantId,
                'type'       => $type,
                'title'      => $title,
                'body'       => $body,
                'link'       => $link,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
}
