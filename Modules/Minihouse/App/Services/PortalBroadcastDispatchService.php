<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;

// Logic DÙNG CHUNG giữa Api\Admin\Minihouse\PushNotificationController (app di động gọi) VÀ
// Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource (soạn ngay trong panel) — tách
// riêng để 2 nơi gọi vào tính năng "gửi thông báo đẩy hàng loạt" này không lệch nhau (VD 1 nơi quên
// áp lại giới hạn toà nhà). Nhận $permittedBuildingIds thay vì tự tính, vì API lấy qua
// ScopesToMinihouseBuilding::permittedBuildingIds($request) còn Filament lấy trực tiếp qua
// auth()->user()->rootBuildingIds() — 2 nguồn Request khác nhau nhưng cùng ý nghĩa "mảng rỗng =
// không giới hạn (super_admin)".
class PortalBroadcastDispatchService
{
    /** @param array<int> $permittedBuildingIds
     *  @param array<int> $tenantIds
     *  @return array<int> */
    public static function resolveTenantIds(array $permittedBuildingIds, string $sentFor, array $tenantIds): array
    {
        $query = Tenant::query();

        if ($sentFor === PortalBroadcast::SENT_FOR_ALL) {
            $query->whereHas('pushTokens');
        } else {
            $query->whereIn('id', $tenantIds);
        }

        // Tenant.room_id là cột CACHE phòng hiện tại (đứng tên chính lẫn ở cùng đều được đồng bộ vào
        // đây — xem ContractObserver::syncTenant()), đáng tin hơn quan hệ contracts() (BelongsToMany
        // qua minihouse_contract_tenants, CHỈ chứa người ở cùng — khách đứng tên chính nằm ở
        // Contract.tenant_id, không qua bảng pivot đó).
        if (! empty($permittedBuildingIds)) {
            $query->whereHas('room', fn ($q) => $q->withoutGlobalScopes()->whereIn('building_id', $permittedBuildingIds));
        }

        return $query->pluck('id')->all();
    }

    /** @param array<int> $tenantIds */
    public static function dispatchNow(PortalBroadcast $broadcast, array $tenantIds): void
    {
        if (empty($tenantIds)) {
            $broadcast->update(['sent_at' => now()]);

            return;
        }

        $tenants = Tenant::whereIn('id', $tenantIds)->get();
        $result  = PortalNotificationService::notifyMany($tenants, PortalNotification::TYPE_MANUAL, $broadcast->title, $broadcast->body, $broadcast->link);

        $broadcast->update([
            'sent_at'         => now(),
            'recipient_count' => $result['sent'],
        ]);
    }

    /** @return array<int> */
    public static function sourceTenantIds(PortalBroadcast $source): array
    {
        if (! empty($source->tenant_ids)) {
            return $source->tenant_ids;
        }

        // Bản gốc gửi NGAY (không lên lịch) nên không có snapshot — tra ngược lại từ chính các dòng
        // PortalNotification đã tạo cho lần gửi đó (khớp title+created_at, không có khoá liên kết
        // trực tiếp vì cố tình KHÔNG dựng bảng recipient riêng, xem migration).
        return PortalNotification::where('title', $source->title)
            ->whereBetween('created_at', [$source->created_at->subMinute(), $source->created_at->addMinute()])
            ->pluck('tenant_id')
            ->unique()
            ->values()
            ->all();
    }
}
