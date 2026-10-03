<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\TTLock\App\Services\TTLockService;

class TenantRoomUnlockService
{
    public const TARGET_ROOM = 'room';
    public const TARGET_GATE = 'gate';

    // Các khoá khách thuê được bấm mở từ xa: khoá PHÒNG (1 nút — phòng 2 ổ "mở cùng lúc" vẫn là 1 nút)
    // và từng khoá CỔNG của toà nhà (TtlockSetting::gateLockIds()). Portal/app dựng nút theo đúng danh
    // sách này và gửi lại target (+ lock_id với khoá cổng); unlock() chỉ nhận khoá nằm trong danh sách.
    //
    // @return list<array{target: string, lock_id: int, name: string}>
    public function locks(Contract $contract): array
    {
        $room = $contract->room;

        if (! $room) {
            return [];
        }

        $locks = [];

        if ($room->lock_id) {
            $locks[] = ['target' => self::TARGET_ROOM, 'lock_id' => (int) $room->lock_id, 'name' => 'Phòng ' . $room->code];
        }

        $gateIds = array_values(array_diff(
            TtlockSetting::forBuilding((int) $room->building_id)->gateLockIds(),
            array_filter([(int) $room->lock_id, (int) $room->lock_id_checkout]),
        ));
        $aliases = $gateIds ? $this->lockAliases((int) $room->building_id) : [];

        foreach ($gateIds as $i => $lockId) {
            $locks[] = [
                'target'  => self::TARGET_GATE,
                'lock_id' => $lockId,
                'name'    => $aliases[$lockId] ?? (count($gateIds) > 1 ? 'Cổng ' . ($i + 1) : 'Cổng'),
            ];
        }

        return $locks;
    }

    /** @return array{success: bool, status: int, message: string, type?: string} */
    public function unlock(Tenant $tenant, Contract $contract, string $target = self::TARGET_ROOM, ?int $lockId = null): array
    {
        if ($contract->status !== Contract::STATUS_ACTIVE
            || $contract->start_date?->isFuture()
            || ($contract->end_date && $contract->end_date->isPast())) {
            return $this->failure(422, 'Hợp đồng chưa hoặc không còn hiệu lực.');
        }

        $room  = $contract->room;
        $isGate = $target === self::TARGET_GATE;
        $candidates = collect($room ? $this->locks($contract) : [])->where('target', $target);
        $lock  = $candidates->first(fn (array $l) => $lockId === null || ! $isGate || $l['lock_id'] === $lockId);

        if (! $lock) {
            return $this->failure(422, match (true) {
                ! $isGate                 => 'Phòng chưa được cấu hình khóa TTLock.',
                $candidates->isNotEmpty() => 'Khóa cổng không hợp lệ.',
                default                   => 'Toà nhà chưa cấu hình khóa cổng TTLock.',
            });
        }

        if ($room->emergency_locked_at) {
            return $this->failure(
                423,
                'Quyền mở cửa qua ứng dụng đang bị khóa khẩn cấp. Vui lòng liên hệ chủ nhà.',
                'emergency_locked',
            );
        }

        // Toà nhà "cấp mã sau khi thu tiền" mà hợp đồng chưa thu cọc/chưa thanh toán hoá đơn nào thì cũng
        // chưa được mở từ xa — cùng điều kiện với việc được cấp mã (ContractTtlockService::canChangeCode()).
        if (! ContractTtlockService::canChangeCode($contract)) {
            return $this->failure(422, 'Chưa thể mở khóa — hợp đồng chưa được xác nhận thu cọc hoặc thanh toán hoá đơn đầu tiên.', 'payment_required');
        }

        $ttlock = TTLockService::forBuilding((int) $room->building_id);
        if (! $ttlock) {
            return $this->failure(422, 'Tòa nhà chưa kết nối TTLock. Vui lòng dùng mật mã hoặc thẻ dự phòng.');
        }

        $both = ! $isGate && $room->unlock_both_locks && $room->lock_id && $room->lock_id_checkout;
        $opened = $both
            ? $ttlock->remoteUnlockBoth((int) $room->lock_id, (int) $room->lock_id_checkout)['success']
            : $ttlock->remoteUnlock($lock['lock_id']);

        Log::info('MiniHouse tenant remote unlock ' . $target, [
            'tenant_id' => $tenant->id,
            'contract_id' => $contract->id,
            'room_id' => $room->id,
            'lock_id' => $lock['lock_id'],
            'success' => $opened,
        ]);

        return $opened
            ? ['success' => true, 'status' => 200, 'message' => $isGate ? 'Cổng đã được mở.' : 'Cửa đã được mở.']
            : $this->failure(503, 'Không thể mở cửa tự động. Vui lòng dùng mật mã hoặc thẻ dự phòng.');
    }

    // Tên khoá (alias đặt trong app TTLock) để hiện trên nút "Mở cổng" — cache ngắn, không gọi TTLock
    // /v3/lock/list mỗi lần khách mở trang; không lấy được thì locks() tự dùng tên "Cổng".
    //
    // @return array<int, string>
    private function lockAliases(int $buildingId): array
    {
        $key = "minihouse_ttlock_lock_aliases_{$buildingId}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $aliases = [];

        foreach (TTLockService::forBuilding($buildingId)?->getLockList() ?? [] as $lock) {
            if (filled($lock['lockAlias'] ?? $lock['lockName'] ?? null)) {
                $aliases[(int) $lock['lockId']] = (string) ($lock['lockAlias'] ?? $lock['lockName']);
            }
        }

        if ($aliases) {
            Cache::put($key, $aliases, now()->addMinutes(10));
        }

        return $aliases;
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
