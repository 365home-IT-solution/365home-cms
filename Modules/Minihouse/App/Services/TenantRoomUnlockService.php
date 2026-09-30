<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Tenant;
use Modules\TTLock\App\Services\TTLockService;

class TenantRoomUnlockService
{
    /** @return array{success: bool, status: int, message: string, type?: string} */
    public function unlock(Tenant $tenant, Contract $contract): array
    {
        if ($contract->status !== Contract::STATUS_ACTIVE
            || $contract->start_date?->isFuture()
            || ($contract->end_date && $contract->end_date->isPast())) {
            return $this->failure(422, 'Hợp đồng chưa hoặc không còn hiệu lực.');
        }

        $room = $contract->room;
        if (! $room || ! $room->lock_id) {
            return $this->failure(422, 'Phòng chưa được cấu hình khóa TTLock.');
        }

        if ($room->emergency_locked_at) {
            return $this->failure(
                423,
                'Quyền mở cửa qua ứng dụng đang bị khóa khẩn cấp. Vui lòng liên hệ chủ nhà.',
                'emergency_locked',
            );
        }

        $ttlock = TTLockService::forBuilding((int) $room->building_id);
        if (! $ttlock) {
            return $this->failure(422, 'Tòa nhà chưa kết nối TTLock. Vui lòng dùng mật mã hoặc thẻ dự phòng.');
        }

        $both = $room->unlock_both_locks && $room->lock_id && $room->lock_id_checkout;
        $opened = $both
            ? $ttlock->remoteUnlockBoth((int) $room->lock_id, (int) $room->lock_id_checkout)['success']
            : $ttlock->remoteUnlock((int) $room->lock_id);

        Log::info('MiniHouse tenant remote unlock room', [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'room_id' => $room->id,
            'success' => $opened,
        ]);

        return $opened
            ? ['success' => true, 'status' => 200, 'message' => 'Cửa đã được mở.']
            : $this->failure(503, 'Không thể mở cửa tự động. Vui lòng dùng mật mã hoặc thẻ dự phòng.');
    }

    /** @return array{success: false, status: int, message: string, type?: string} */
    private function failure(int $status, string $message, ?string $type = null): array
    {
        return array_filter([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'type' => $type,
        ], fn ($value) => $value !== null);
    }
}
