<?php

namespace Modules\VehicleRental\App\Services;

use DomainException;
use Illuminate\Support\Facades\Schema;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Services\MotrakMappingService;
use Modules\VehicleRental\App\Models\Vehicle;

/**
 * Gắn thiết bị GPS Motrak cho XE CHO THUÊ ngay lúc thêm/sửa xe (CMS + API). Liên kết thật là cột motrak_vehicles.rental_vehicle_id
 * (một thiết bị ↔ một xe). Thiết bị lấy từ danh sách Motrak đã đồng bộ — nên Token Motrak phải đang hoạt động và đã đồng bộ ít nhất một lần.
 *
 * Gợi ý / tự gắn: biển số xe (đã chuẩn hoá) khớp ĐÚNG MỘT thiết bị chưa ghép. Module Motrak vắng mặt = mọi hàm trả "không có GPS", không lỗi.
 */
class VehicleGpsLinker
{
    public function __construct(private ?MotrakMappingService $mapping = null)
    {
        $this->mapping ??= class_exists(MotrakMappingService::class) ? app(MotrakMappingService::class) : null;
    }

    public function available(): bool
    {
        return $this->mapping !== null && class_exists(MotrakVehicle::class) && Schema::hasTable('motrak_vehicles');
    }

    public function current(Vehicle $vehicle): ?MotrakVehicle
    {
        return $this->available() ? MotrakVehicle::query()->where('rental_vehicle_id', $vehicle->id)->first() : null;
    }

    public function currentId(Vehicle $vehicle): ?int
    {
        return $this->current($vehicle)?->id;
    }

    /** @return array<int, string> id thiết bị → "biển số — địa chỉ · trạng thái" của các thiết bị CHƯA ghép (cộng thiết bị đang ghép với xe này). */
    public function options(?Vehicle $vehicle = null): array
    {
        if (! $this->available()) {
            return [];
        }

        return MotrakVehicle::query()
            ->where(fn ($q) => $q->whereNull('rental_vehicle_id')->when($vehicle?->exists, fn ($w) => $w->orWhere('rental_vehicle_id', $vehicle->id)))
            ->orderBy('plate')->get()
            ->mapWithKeys(fn (MotrakVehicle $d) => [$d->id => trim($d->plate . ' — ' . ($d->address ?: 'chưa có địa chỉ') . ' · ' . $d->connectionLabel())])->all();
    }

    /** Thiết bị chưa ghép có biển số khớp duy nhất với $plate (hoặc null). */
    public function suggestId(?string $plate, ?Vehicle $vehicle = null): ?int
    {
        if (! $this->available() || blank($plate)) {
            return null;
        }

        $matches = MotrakVehicle::query()->where('plate_normalized', Vehicle::normalizePlate($plate))
            ->where(fn ($q) => $q->whereNull('rental_vehicle_id')->when($vehicle?->exists, fn ($w) => $w->orWhere('rental_vehicle_id', $vehicle->id)))->get();

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    /**
     * Gắn xe với thiết bị $motrakId; null = bỏ gắn. Một thiết bị chỉ gắn một xe: thiết bị đang gắn xe khác thì báo lỗi.
     *
     * @throws DomainException
     */
    public function link(Vehicle $vehicle, ?int $motrakId): void
    {
        if (! $this->available()) {
            if ($motrakId !== null) {
                throw new DomainException('Module GPS Motrak chưa sẵn sàng.');
            }

            return;
        }

        $current = $this->current($vehicle);
        if ($motrakId === null) {
            $current && $this->mapping->update($current, $current->imei, null);

            return;
        }
        if ($current && $current->id === $motrakId) {
            return;
        }

        $device = MotrakVehicle::query()->find($motrakId);
        if (! $device) {
            throw new DomainException('Không tìm thấy thiết bị GPS đã chọn.');
        }
        if ($device->rental_vehicle_id !== null && (int) $device->rental_vehicle_id !== $vehicle->id) {
            throw new DomainException("Thiết bị GPS {$device->plate} đã được ghép với xe khác.");
        }

        $current && $this->mapping->update($current, $current->imei, null); // đổi thiết bị: nhả thiết bị cũ trước
        $this->mapping->update($device, $device->imei, $vehicle->id);
    }

    /** Xe mới: nếu biển số khớp duy nhất một thiết bị chưa ghép thì gắn luôn. true = đã gắn. */
    public function autoLinkByPlate(Vehicle $vehicle): bool
    {
        if (! $this->available() || $this->current($vehicle)) {
            return false;
        }
        $id = $this->suggestId($vehicle->plate_display ?: $vehicle->plate, $vehicle);
        if ($id === null) {
            return false;
        }
        $this->link($vehicle, $id);

        return true;
    }
}
