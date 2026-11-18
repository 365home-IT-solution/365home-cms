<?php

namespace Modules\Motrak\App\Services;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Motrak\App\Models\MotrakVehicle;

/**
 * Ánh xạ do quản trị viên nhập: IMEI của thiết bị và xe cho thuê (rental_vehicles) tương ứng. Không bao giờ tự bịa IMEI, không dùng vehicleId thay IMEI.
 * Gợi ý ghép theo biển số chuẩn hoá chỉ là GỢI Ý — chỉ ghép khi quản trị viên xác nhận.
 */
class MotrakMappingService
{
    /** IMEI chuẩn: 14–16 chữ số. */
    public const IMEI_PATTERN = '/^\d{14,16}$/';

    /**
     * @throws DomainException
     */
    public function update(MotrakVehicle $vehicle, ?string $imei, ?int $rentalVehicleId): MotrakVehicle
    {
        $imei = $imei !== null ? trim($imei) : null;
        $imei = $imei === '' ? null : $imei;

        if ($imei !== null) {
            if (! preg_match(self::IMEI_PATTERN, $imei)) {
                throw new DomainException('IMEI phải gồm 14–16 chữ số.');
            }
            if (MotrakVehicle::query()->where('imei', $imei)->where('id', '!=', $vehicle->id)->exists()) {
                throw new DomainException('IMEI này đã được gán cho phương tiện khác.');
            }
        }

        if ($rentalVehicleId !== null) {
            if (! $this->rentalVehicleExists($rentalVehicleId)) {
                throw new DomainException('Xe cho thuê không tồn tại.');
            }
            $other = MotrakVehicle::query()->where('rental_vehicle_id', $rentalVehicleId)->where('id', '!=', $vehicle->id)->first();
            if ($other) {
                throw new DomainException("Xe cho thuê này đã được ghép với thiết bị GPS {$other->plate}.");
            }
        }

        $vehicle->update(['imei' => $imei, 'rental_vehicle_id' => $rentalVehicleId]);

        return $vehicle;
    }

    public function rentalVehicleExists(int $id): bool
    {
        return Schema::hasTable('rental_vehicles') && DB::table('rental_vehicles')->where('id', $id)->whereNull('deleted_at')->exists();
    }

    /** @return array<int, string> id → "biển số — hãng dòng" của các xe cho thuê CHƯA ghép GPS (cộng xe đang ghép với $include). */
    public function rentalVehicleOptions(?int $include = null): array
    {
        if (! Schema::hasTable('rental_vehicles')) {
            return [];
        }

        $linked = MotrakVehicle::query()->whereNotNull('rental_vehicle_id')->pluck('rental_vehicle_id')->all();

        return DB::table('rental_vehicles')->whereNull('deleted_at')
            ->when($linked, fn ($q) => $q->where(fn ($w) => $w->whereNotIn('id', $linked)->when($include, fn ($x) => $x->orWhere('id', $include))))
            ->orderBy('plate')->get(['id', 'plate_display', 'brand', 'model'])
            ->mapWithKeys(fn ($r) => [$r->id => "{$r->plate_display} — {$r->brand} {$r->model}"])->all();
    }

    /**
     * Gợi ý ghép: thiết bị chưa ghép có biển số chuẩn hoá TRÙNG đúng 1 xe cho thuê chưa ghép (và ngược lại). Trùng nhiều bên → bỏ qua (mơ hồ).
     *
     * @return list<array{motrak_vehicle_id: int, motrak_plate: string, rental_vehicle_id: int, rental_plate: string}>
     */
    public function suggestions(): array
    {
        if (! Schema::hasTable('rental_vehicles')) {
            return [];
        }

        $linkedRental = MotrakVehicle::query()->whereNotNull('rental_vehicle_id')->pluck('rental_vehicle_id')->all();
        $rental = DB::table('rental_vehicles')->whereNull('deleted_at')->whereNotIn('id', $linkedRental ?: [0])->get(['id', 'plate', 'plate_display'])->groupBy('plate');
        $devices = MotrakVehicle::query()->whereNull('rental_vehicle_id')->get()->groupBy('plate_normalized');

        $out = [];
        foreach ($devices as $norm => $list) {
            $match = $rental->get($norm);
            if ($list->count() === 1 && $match && $match->count() === 1) {
                $out[] = ['motrak_vehicle_id' => $list[0]->id, 'motrak_plate' => $list[0]->plate, 'rental_vehicle_id' => $match[0]->id, 'rental_plate' => $match[0]->plate_display];
            }
        }

        return $out;
    }

    /**
     * Áp dụng các gợi ý do quản trị viên chọn (kiểm tra lại từng cặp, không tin dữ liệu gửi lên).
     *
     * @param  list<int>  $motrakVehicleIds
     * @return int số cặp đã ghép
     */
    public function applySuggestions(array $motrakVehicleIds): int
    {
        $valid = collect($this->suggestions())->keyBy('motrak_vehicle_id');
        $applied = 0;

        foreach ($motrakVehicleIds as $id) {
            if ($s = $valid->get((int) $id)) {
                MotrakVehicle::query()->whereKey($s['motrak_vehicle_id'])->update(['rental_vehicle_id' => $s['rental_vehicle_id']]);
                $applied++;
            }
        }

        return $applied;
    }
}
