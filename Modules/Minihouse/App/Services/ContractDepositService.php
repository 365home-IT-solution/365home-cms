<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;

// "Xác nhận đã thu cọc" — dùng chung cho nút ở EditContract (Filament) và ContractController (API).
// Trước đây tiền cọc chỉ là 1 con số trên Hợp đồng, không có bước ghi nhận ĐÃ THU: không hiện trong sổ
// Thu Chi (trong khi hoàn cọc thì có — xem recordDepositRefundTransaction()), và không có mốc nào để
// toà nhà "cấp mã TTLock sau khi thu tiền" dựa vào. Đổi deposit_paid_at -> ContractObserver tự cấp mã
// (xem ContractTtlockService). Cọc KHÔNG bắt buộc: hợp đồng không cọc thì không cần gọi tới đây.
class ContractDepositService
{
    public static function canMarkPaid(Contract $contract): bool
    {
        return $contract->status === Contract::STATUS_ACTIVE
            && (float) $contract->deposit_amount > 0
            && $contract->deposit_paid_at === null;
    }

    public static function markPaid(Contract $contract): bool
    {
        if (! self::canMarkPaid($contract)) {
            return false;
        }

        $room = $contract->room_id ? Room::withoutGlobalScope('activeBuilding')->find($contract->room_id) : null;

        Transaction::create([
            'contract_id'      => $contract->id,
            'building_id'      => $room?->building_id,
            'type'             => Transaction::TYPE_IN,
            'category'         => Transaction::CATEGORY_DEPOSIT,
            'amount'           => $contract->deposit_amount,
            'transaction_date' => now(),
            'note'             => 'Thu cọc hợp đồng #' . $contract->id . ($room?->code ? ' - phòng ' . $room->code : ''),
        ]);

        $contract->update(['deposit_paid_at' => now()]);

        return true;
    }
}
