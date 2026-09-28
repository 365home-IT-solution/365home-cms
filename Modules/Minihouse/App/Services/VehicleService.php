<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Models\VehicleRate;

// Nghiệp vụ xe khách thuê: chuẩn hoá biển số, kiểm tra giới hạn (CHỈ cảnh báo, không chặn), tính phí
// gửi xe theo bảng giá của Toà nhà và đưa vào hoá đơn dưới dạng dòng phụ thu (InvoiceItem).
//
// LỖI THẬT đã gặp: mọi hàm ở đây dùng Vehicle::withoutGlobalScopes() (bỏ HẾT global scope, kể cả
// SoftDeletingScope) để không bị lọc theo Toà nhà đang xem trong panel — nhưng vô tình cũng làm SỐNG
// LẠI các xe ĐÃ XOÁ MỀM (nhân viên bấm "Xoá" ở trang Xe khách thuê chỉ soft-delete, deleted_at vẫn
// còn nguyên status='active'): xe đã xoá vẫn bị invoiceItems()/limitWarnings()/plateInUse() tính vào,
// khiến hoá đơn hiện lại tên biển số của xe tưởng đã xoá xong (dù trang danh sách không còn thấy nó).
// Đổi hết sang withoutGlobalScope('activeBuilding') — CHỈ bỏ đúng 1 scope lọc Toà nhà, giữ nguyên
// SoftDeletingScope để xe đã xoá luôn bị loại khỏi mọi tính toán.
class VehicleService
{
    // "59A1-123.45" / "59a1 123 45" → "59A112345": bỏ hết ký tự không phải chữ/số, viết hoa. Dùng để
    // so trùng biển số bất kể cách gõ dấu gạch, chấm, khoảng trắng.
    public static function normalizePlate(string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($plate)) ?? '';
    }

    public static function rateFor(int $buildingId, string $type): ?VehicleRate
    {
        return VehicleRate::query()->where('building_id', $buildingId)->where('vehicle_type', $type)->first();
    }

    // Phí tháng của 1 xe: theo bảng giá của toà × loại xe, chưa cấu hình = 0.
    public static function monthlyFee(Vehicle $vehicle): float
    {
        return (float) (self::rateFor((int) $vehicle->building_id, (string) $vehicle->vehicle_type)?->monthly_fee ?? 0);
    }

    // Biển số đã có xe ĐANG GỬI/chờ duyệt khác trong cùng toà hay chưa (trừ chính xe đang xét).
    public static function plateInUse(int $buildingId, string $plateNormalized, ?int $exceptId = null): bool
    {
        return Vehicle::withoutGlobalScope('activeBuilding')
            ->where('building_id', $buildingId)
            ->where('plate', $plateNormalized)
            ->whereIn('status', [Vehicle::STATUS_ACTIVE, Vehicle::STATUS_PENDING])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * Cảnh báo vượt giới hạn — KHÔNG chặn. Đếm xe đang gửi + chờ duyệt (không tính chính xe này).
     *
     * @return array<int, string>
     */
    public static function limitWarnings(Vehicle $vehicle): array
    {
        $rate = self::rateFor((int) $vehicle->building_id, (string) $vehicle->vehicle_type);

        if (! $rate) {
            return [];
        }

        $warnings = [];
        $label    = mb_strtolower(Vehicle::TYPES[$vehicle->vehicle_type] ?? (string) $vehicle->vehicle_type);
        $live     = fn () => Vehicle::withoutGlobalScope('activeBuilding')
            ->where('building_id', $vehicle->building_id)
            ->where('vehicle_type', $vehicle->vehicle_type)
            ->whereIn('status', [Vehicle::STATUS_ACTIVE, Vehicle::STATUS_PENDING])
            ->when($vehicle->exists, fn ($q) => $q->where('id', '!=', $vehicle->id));

        if ($rate->max_per_contract !== null && $vehicle->contract_id) {
            $count = $live()->where('contract_id', $vehicle->contract_id)->count() + 1;

            if ($count > $rate->max_per_contract) {
                $warnings[] = "Hợp đồng này đã có {$count} {$label}, vượt giới hạn {$rate->max_per_contract} xe/hợp đồng.";
            }
        }

        if ($rate->capacity !== null) {
            $count = $live()->count() + 1;

            if ($count > $rate->capacity) {
                $warnings[] = "Toà nhà đã có {$count} {$label}, vượt sức chứa {$rate->capacity} chỗ.";
            }
        }

        return $warnings;
    }

    /**
     * Cảnh báo vượt giới hạn tính trên CẢ hợp đồng (dùng sau khi lưu tab Phương tiện): số xe đang gửi/chờ
     * duyệt theo loại so với giới hạn mỗi hợp đồng, và tổng số chỗ của toà.
     *
     * @return array<int, string>
     */
    public static function contractLimitWarnings(Contract $contract): array
    {
        $buildingId = (int) ($contract->room?->building_id ?? \Modules\Minihouse\App\Models\Room::withoutGlobalScopes()->whereKey($contract->room_id)->value('building_id'));
        $warnings   = [];

        foreach (Vehicle::TYPES as $type => $label) {
            $rate = self::rateFor($buildingId, $type);

            if (! $rate) {
                continue;
            }

            $live = fn () => Vehicle::withoutGlobalScope('activeBuilding')->where('building_id', $buildingId)->where('vehicle_type', $type)
                ->whereIn('status', [Vehicle::STATUS_ACTIVE, Vehicle::STATUS_PENDING]);

            $mine = $live()->where('contract_id', $contract->id)->count();

            if ($rate->max_per_contract !== null && $mine > $rate->max_per_contract) {
                $warnings[] = "Hợp đồng này có {$mine} " . mb_strtolower($label) . ", vượt giới hạn {$rate->max_per_contract} xe/hợp đồng.";
            }

            $all = $live()->count();

            if ($rate->capacity !== null && $all > $rate->capacity) {
                $warnings[] = "Toà nhà có {$all} " . mb_strtolower($label) . ", vượt sức chứa {$rate->capacity} chỗ.";
            }
        }

        return $warnings;
    }

    public static function approve(Vehicle $vehicle, ?string $userId): Vehicle
    {
        $vehicle->update([
            'status'        => Vehicle::STATUS_ACTIVE,
            'approved_by'   => $userId,
            'approved_at'   => now(),
            'reject_reason' => null,
            'start_date'    => $vehicle->start_date ?? now()->toDateString(),
        ]);

        return $vehicle;
    }

    public static function reject(Vehicle $vehicle, ?string $userId, ?string $reason): Vehicle
    {
        $vehicle->update([
            'status'        => Vehicle::STATUS_REJECTED,
            'approved_by'   => $userId,
            'approved_at'   => now(),
            'reject_reason' => $reason,
        ]);

        return $vehicle;
    }

    public static function deactivate(Vehicle $vehicle): Vehicle
    {
        $vehicle->update(['status' => Vehicle::STATUS_INACTIVE, 'end_date' => $vehicle->end_date ?? now()->toDateString()]);

        return $vehicle;
    }

    // Khi hợp đồng kết thúc/thanh lý/huỷ: mọi xe đang gửi tự chuyển sang "đã ngưng" (không tính phí tiếp).
    public static function deactivateForContract(Contract $contract, ?Carbon $endDate = null): int
    {
        return Vehicle::withoutGlobalScope('activeBuilding')
            ->where('contract_id', $contract->id)
            ->whereIn('status', [Vehicle::STATUS_ACTIVE, Vehicle::STATUS_PENDING])
            ->get()
            ->each(fn (Vehicle $v) => $v->update([
                'status'   => $v->status === Vehicle::STATUS_PENDING ? Vehicle::STATUS_REJECTED : Vehicle::STATUS_INACTIVE,
                'end_date' => $endDate?->toDateString() ?? $v->end_date ?? now()->toDateString(),
                'reject_reason' => $v->status === Vehicle::STATUS_PENDING ? 'Hợp đồng đã kết thúc.' : $v->reject_reason,
            ]))
            ->count();
    }

    /**
     * Khách tự khai xe trên Portal/App → status "pending", chờ nhân viên duyệt. Chỉ khai được cho hợp
     * đồng ĐANG HIỆU LỰC của chính mình (kể cả người ở cùng). Trả [Vehicle|null, lỗi|null].
     *
     * @param  array<string, mixed>  $data  plate, vehicle_type, name, contract_id?
     * @return array{0: ?Vehicle, 1: ?string}
     */
    public static function declareForTenant(\Modules\Minihouse\App\Models\Tenant $tenant, array $data, ?string $documentPhotoPath = null): array
    {
        $contracts = TenantPortalService::tenantContracts($tenant)->where('status', Contract::STATUS_ACTIVE);
        $contract  = filled($data['contract_id'] ?? null) ? $contracts->firstWhere('id', (int) $data['contract_id']) : $contracts->first();

        if (! $contract || ! $contract->room) {
            return [null, 'Bạn chưa có hợp đồng đang hiệu lực để khai báo xe.'];
        }

        $buildingId = (int) $contract->room->building_id;
        $plate      = self::normalizePlate((string) $data['plate']);

        if ($plate === '' || self::plateInUse($buildingId, $plate)) {
            return [null, 'Biển số không hợp lệ hoặc đã được đăng ký/chờ duyệt trong toà nhà.'];
        }

        $vehicle = Vehicle::withoutGlobalScope('activeBuilding')->create([
            'building_id'        => $buildingId,
            'contract_id'        => $contract->id,
            'tenant_id'          => $tenant->id,
            'plate_display'      => (string) $data['plate'],
            'vehicle_type'       => $data['vehicle_type'],
            'name'               => $data['name'] ?? null,
            'document_photo'     => $documentPhotoPath,
            'status'             => Vehicle::STATUS_PENDING,
            'requested_by'       => 'tenant',
        ]);

        return [$vehicle, null];
    }

    // Xe của CHÍNH khách này (mọi trạng thái, mới nhất trước) — cho Portal/App.
    public static function vehiclesOf(\Modules\Minihouse\App\Models\Tenant $tenant): \Illuminate\Support\Collection
    {
        return Vehicle::withoutGlobalScope('activeBuilding')->where('tenant_id', $tenant->id)->orderByDesc('id')->get();
    }

    /**
     * Dòng phí gửi xe cho 1 chu kỳ hoá đơn — TÍNH PHÍ TRỌN GÓI THEO LOẠI XE, không nhân theo số xe: 1
     * hợp đồng có 2 xe máy cùng lúc vẫn chỉ trả ĐÚNG 1 lần phí xe máy/tháng (đúng giá trên Bảng giá gửi
     * xe của toà), dù "Tối đa mỗi hợp đồng" cho phép nhiều hơn 1 xe — giới hạn đó chỉ để CẢNH BÁO số
     * lượng xe (xem limitWarnings()/contractLimitWarnings()), không phải bậc giá.
     *
     * Với mỗi loại xe hợp đồng đang có, khoảng thời gian tính phí = HỢP của khoảng gửi xe của TỪNG xe
     * loại đó trong chu kỳ (xe sớm nhất bắt đầu → xe muộn nhất ngưng) — vd xe A gửi từ đầu tháng, xe B
     * thêm giữa tháng: vẫn tính đủ tháng vì loại xe máy đã có mặt từ đầu tháng.
     *
     * @return array<int, array{surcharge_id: null, name: string, amount: float}>
     */
    public static function invoiceItems(Contract $contract, Carbon $periodStart, Carbon $periodEnd): array
    {
        $fullDays = $periodStart->diffInDays($periodEnd) + 1;
        $items    = [];

        $vehicles = Vehicle::withoutGlobalScope('activeBuilding')
            ->where('contract_id', $contract->id)
            ->where(fn ($q) => $q->where('status', Vehicle::STATUS_ACTIVE)->orWhere(fn ($q2) => $q2->where('status', Vehicle::STATUS_INACTIVE)->whereNotNull('end_date')))
            ->orderBy('id')
            ->get()
            ->groupBy('vehicle_type');

        foreach ($vehicles as $type => $group) {
            $fee = self::monthlyFee($group->first());

            if ($fee <= 0) {
                continue;
            }

            $from = null;
            $to   = null;

            foreach ($group as $vehicle) {
                $vFrom = $vehicle->start_date && $vehicle->start_date->gt($periodStart) ? $vehicle->start_date->copy() : $periodStart->copy();
                $vTo   = $vehicle->end_date && $vehicle->end_date->lt($periodEnd) ? $vehicle->end_date->copy() : $periodEnd->copy();

                if ($vFrom->gt($vTo)) {
                    continue; // xe này không thật sự gửi trong chu kỳ (VD ngưng trước periodStart)
                }

                $from = $from === null || $vFrom->lt($from) ? $vFrom : $from;
                $to   = $to === null || $vTo->gt($to) ? $vTo : $to;
            }

            if ($from === null) {
                continue;
            }

            $days   = $from->diffInDays($to) + 1;
            $amount = $days >= $fullDays ? $fee : round($fee / $fullDays * $days, 0);

            $items[] = [
                'surcharge_id' => null,
                'name'         => 'Phí gửi xe — ' . (Vehicle::TYPES[$type] ?? $type) . ' (' . $group->pluck('plate_display')->implode(', ') . ')',
                'amount'       => (float) $amount,
            ];
        }

        return $items;
    }
}
