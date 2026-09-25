<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Filament\Resources\PortalBroadcastResource;
use Modules\Minihouse\App\Models\PortalBroadcast;
use Modules\Minihouse\App\Services\PortalBroadcastDispatchService;

// Mirror App\Filament\Resources\NotificationFcmResource\Pages\CreateNotificationFcm (Home) — gửi
// thật qua PortalBroadcastDispatchService, DÙNG CHUNG với Api\Admin\Minihouse\
// PushNotificationController::store() để 2 nơi (panel + app di động) không lệch hành vi.
class CreatePortalBroadcast extends CreateRecord
{
    protected static string $resource = PortalBroadcastResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $tenantIds = $data['tenant_ids'] ?? [];
        $sentFor   = $data['sent_for'] ?? PortalBroadcast::SENT_FOR_TENANTS;
        unset($data['tenant_ids']);

        // Filament dùng auth() trực tiếp (không có $request thật như API) — super_admin không giới
        // hạn (mảng rỗng), tài khoản khác chỉ được gửi trong đúng toà nhà mình quản lý, cùng nguyên
        // tắc ScopesToMinihouseBuilding các controller API MiniHouse khác đang áp dụng.
        $permitted = auth()->user()->isSuperAdmin() ? [] : auth()->user()->rootBuildingIds();
        $resolvedIds = PortalBroadcastDispatchService::resolveTenantIds($permitted, $sentFor, $tenantIds);

        $scheduledAt = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $data['created_by']      = auth()->id();
        $data['recipient_count'] = count($resolvedIds);
        $data['tenant_ids']      = $isScheduled ? $resolvedIds : null;

        $record = static::getModel()::create($data);

        if (! $isScheduled) {
            PortalBroadcastDispatchService::dispatchNow($record, $resolvedIds);
        }

        return $record;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record->isPending()) {
            Notification::make()
                ->info()
                ->title('Đã lên lịch gửi thông báo')
                ->body('Sẽ gửi vào lúc ' . $record->scheduled_at->format('d/m/Y H:i') . '.')
                ->send();

            return;
        }

        if ($record->recipient_count === 0) {
            Notification::make()
                ->warning()
                ->title('Không có khách thuê nào nhận được thông báo')
                ->body('Không tìm thấy khách thuê hợp lệ trong phạm vi bạn quản lý.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title("Đã gửi thành công đến {$record->recipient_count} khách thuê")
            ->send();
    }
}
