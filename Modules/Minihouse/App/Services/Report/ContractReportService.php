<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services\Report;

use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Contract;

// Báo cáo hợp đồng — tương đương tinh thần "booking report" bên Home nhưng theo đúng nghiệp vụ cho
// thuê dài hạn: hợp đồng MỚI KÝ trong kỳ, hợp đồng KẾT THÚC (hết hạn/huỷ, checkout_at trong kỳ — cả
// 2 trường hợp đều dùng chung cột checkout_at, xem EditContract::getHeaderActions() "Huỷ hợp đồng"),
// và hợp đồng SẮP HẾT HẠN (không phụ thuộc kỳ báo cáo — luôn tính từ hôm nay, để cảnh báo kịp thời
// bất kể đang xem báo cáo kỳ nào).
class ContractReportService
{
    private const DEFAULT_EXPIRING_SOON_DAYS = 30;

    /**
     * @param int[] $buildingIds
     */
    public static function summary(array $buildingIds, Carbon $start, Carbon $end, int $expiringSoonDays = self::DEFAULT_EXPIRING_SOON_DAYS): array
    {
        // withoutGlobalScopes() ở TỪNG query gốc bên dưới — 1 hợp đồng đã kết thúc/huỷ xong rồi bị
        // dọn (soft-delete) vẫn phải tính đúng vào số liệu "hợp đồng kết thúc trong kỳ" của đúng kỳ
        // nó thực sự kết thúc, cùng nguyên tắc đã áp dụng cho công nợ ở FinancialReportService.
        $newContracts = self::scopeToBuilding(
            Contract::query()->withoutGlobalScopes()->whereBetween('start_date', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )->count();

        $endedContracts = self::scopeToBuilding(
            Contract::query()->withoutGlobalScopes()
                ->whereIn('status', [Contract::STATUS_EXPIRED, Contract::STATUS_CANCELLED])
                ->whereBetween('checkout_at', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )->count();

        $cancelledContracts = self::scopeToBuilding(
            Contract::query()->withoutGlobalScopes()
                ->where('status', Contract::STATUS_CANCELLED)
                ->whereBetween('checkout_at', [$start->toDateString(), $end->toDateString()]),
            $buildingIds,
        )->count();

        $now = Carbon::now();

        // withoutGlobalScope('activeBuilding') — CHỈ bỏ scope lọc theo toà (đã tự lọc lại bằng
        // scopeToBuilding() bên dưới), KHÔNG dùng withoutGlobalScopes() (không tham số) như các
        // metric ở trên: hàm đó xoá LUÔN CẢ SoftDeletingScope của Contract, khiến hợp đồng đã bị
        // soft-delete (VD staff xoá nhầm 1 hợp đồng còn "active") vẫn bị đếm là "đang hiệu lực"/"sắp
        // hết hạn" dù không còn hiển thị ở bất kỳ đâu khác trong hệ thống — BUG THẬT phát hiện qua
        // review: khác các số liệu "kết thúc/huỷ trong kỳ" (cố ý giữ nguyên cả hợp đồng đã bị dọn để
        // không bỏ sót lịch sử), 1 bản ghi đã bị xoá không thể còn là "đang hiệu lực" được.
        $expiringSoon = self::scopeToBuilding(
            Contract::query()->withoutGlobalScope('activeBuilding')
                ->where('status', Contract::STATUS_ACTIVE)
                ->whereBetween('end_date', [$now->toDateString(), $now->copy()->addDays($expiringSoonDays)->toDateString()]),
            $buildingIds,
        )
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'tenant' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('end_date')
            ->get()
            ->map(fn (Contract $contract) => [
                'contract_id'    => $contract->id,
                'room'           => $contract->room?->code ?? '—',
                'tenant'         => $contract->tenant?->fullname ?? '—',
                'end_date'       => optional($contract->end_date)->toDateString(),
                'days_remaining' => $now->copy()->startOfDay()->diffInDays(Carbon::parse($contract->end_date)->startOfDay(), false),
            ])
            ->values()
            ->all();

        $activeContracts = self::scopeToBuilding(
            Contract::query()->withoutGlobalScope('activeBuilding')->where('status', Contract::STATUS_ACTIVE),
            $buildingIds,
        )->count();

        return [
            'active_contracts'    => $activeContracts,
            'new_contracts'       => $newContracts,
            'ended_contracts'     => $endedContracts,
            'cancelled_contracts' => $cancelledContracts,
            'expiring_soon'       => $expiringSoon,
        ];
    }

    /**
     * @param int[] $buildingIds
     */
    private static function scopeToBuilding($query, array $buildingIds)
    {
        return $query->whereHas('room', fn ($q) => $q->withoutGlobalScopes()->whereIn('building_id', $buildingIds));
    }
}
