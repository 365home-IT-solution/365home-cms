<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Announcement;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\PortalNotification;

// Tạo xong 1 Announcement thì tự "phát" ngay ra Portal cho TOÀN BỘ khách thuê đang có hợp đồng "Đang
// hiệu lực" trong phạm vi (building_id NULL = mọi toà) — đứng tên chính LẪN ở cùng, vì thông báo kiểu
// "cắt nước"/"bảo trì thang máy" ảnh hưởng tới TẤT CẢ người đang ở, không riêng người đứng tên.
class AnnouncementObserver
{
    public function created(Announcement $announcement): void
    {
        $contractIds = Contract::withoutGlobalScopes()
            ->where('status', Contract::STATUS_ACTIVE)
            ->when(
                $announcement->building_id,
                fn ($query) => $query->whereHas('room', fn ($q) => $q->withoutGlobalScopes()->where('building_id', $announcement->building_id)),
            )
            ->pluck('id');

        if ($contractIds->isEmpty()) {
            return;
        }

        $tenantIds = ContractTenant::whereIn('contract_id', $contractIds)->pluck('tenant_id')->unique();

        if ($tenantIds->isEmpty()) {
            return;
        }

        $now = now();

        PortalNotification::insert(
            $tenantIds->map(fn (int $tenantId) => [
                'tenant_id'  => $tenantId,
                'type'       => PortalNotification::TYPE_ANNOUNCEMENT,
                'title'      => $announcement->title,
                'body'       => $announcement->body,
                'link'       => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );
    }
}
