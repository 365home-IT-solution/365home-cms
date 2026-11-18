<?php

namespace Modules\VehicleRental\App\Services;

use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleRatePlan;

/**
 * Bộ gói giá MẪU (số giả) để dựng nhanh khi thêm xe — admin sửa lại trên CMS (tab "Gói giá" của xe). Chỉ tạo khi xe
 * chưa có gói nào, tránh ghi đè giá đã chỉnh.
 */
class VehicleRatePlanDefaults
{
    /** Xe máy không giới hạn km; ô tô có km bao gồm theo gói. */
    public static function for(string $type): array
    {
        if ($type === Vehicle::TYPE_CAR) {
            return [
                ['name' => 'Gói 4 giờ', 'unit' => 'hour', 'duration_hours' => 4, 'price' => 450000, 'included_km' => 50, 'overtime_hour_price' => 120000],
                ['name' => 'Gói 8 giờ', 'unit' => 'hour', 'duration_hours' => 8, 'price' => 750000, 'included_km' => 100, 'overtime_hour_price' => 120000],
                ['name' => 'Gói 12 giờ', 'unit' => 'hour', 'duration_hours' => 12, 'price' => 950000, 'included_km' => 150, 'overtime_hour_price' => 120000],
                ['name' => 'Gói ngày', 'unit' => 'day', 'duration_hours' => 24, 'price' => 1100000, 'weekend_price' => 1300000, 'included_km' => 300, 'overtime_hour_price' => 120000],
            ];
        }

        return [
            ['name' => 'Gói 4 giờ', 'unit' => 'hour', 'duration_hours' => 4, 'price' => 80000, 'included_km' => null, 'overtime_hour_price' => 20000],
            ['name' => 'Gói 8 giờ', 'unit' => 'hour', 'duration_hours' => 8, 'price' => 120000, 'included_km' => null, 'overtime_hour_price' => 20000],
            ['name' => 'Gói ngày', 'unit' => 'day', 'duration_hours' => 24, 'price' => 150000, 'weekend_price' => 180000, 'included_km' => null, 'overtime_hour_price' => 20000],
        ];
    }

    /** @return int số gói đã tạo (0 nếu xe đã có gói) */
    public static function seed(Vehicle $vehicle): int
    {
        if ($vehicle->ratePlans()->exists()) {
            return 0;
        }

        $extraKm = $vehicle->type === Vehicle::TYPE_CAR ? 5000 : 0;
        $created = 0;
        foreach (self::for($vehicle->type) as $i => $row) {
            $vehicle->ratePlans()->create($row + [
                'extra_km_price'         => $extraKm,
                'overtime_grace_minutes' => 30,
                'is_active'              => true,
                'sort_order'             => $i,
            ]);
            $created++;
        }

        return $created;
    }
}
