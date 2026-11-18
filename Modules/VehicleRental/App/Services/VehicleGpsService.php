<?php

namespace Modules\VehicleRental\App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\Category\Entities\Category;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Models\MotrakVehicleLocation;
use Modules\Motrak\App\Support\MotrakCoordinates;
use Modules\Motrak\App\Support\MotrakTransformer;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleGpsAlert;
use Modules\VehicleRental\App\Models\VehicleHandover;

/**
 * Cầu nối giữa xe thuê và GPS Motrak. Thiết bị GPS được ghép với xe thuê ở motrak_vehicles.rental_vehicle_id (do Super Admin xác nhận).
 *
 * Quy tắc dữ liệu/riêng tư:
 *  - Chỉ ĐỌC dữ liệu đã đồng bộ về DB 365home, không bao giờ gọi Motrak.
 *  - Vị trí xe và hành trình chỉ dành cho nhân viên (API/CMS thuê xe); khách không bao giờ thấy.
 *  - km GPS ("kmage") chỉ để ĐỐI CHIẾU với odometer, không dùng tính tiền (odometer chụp ảnh vẫn là nguồn chính).
 *  - Hành trình chỉ gồm các điểm 365Home đã lưu (xe đang thuê được lưu đầy đủ mọi điểm mới), KHÔNG phải lịch sử đầy đủ của Motrak.
 *  - Module Motrak tắt / chưa có bảng → mọi hàm trả "chưa ghép GPS", không lỗi.
 */
class VehicleGpsService
{
    public const COVERAGE_NOTE = 'Hành trình dựng từ các điểm GPS 365Home đã đồng bộ và lưu (xe đang thuê được lưu mọi điểm mới theo chu kỳ đồng bộ); không phải lịch sử đầy đủ từ Motrak.';

    public function available(): bool
    {
        return class_exists(MotrakVehicle::class) && Schema::hasTable('motrak_vehicles');
    }

    public function cfg(string $key, mixed $default = null): mixed
    {
        return config("vehiclerental.gps.{$key}", $default);
    }

    public function trackerFor(int $rentalVehicleId): ?MotrakVehicle
    {
        return $this->available() ? MotrakVehicle::query()->where('rental_vehicle_id', $rentalVehicleId)->first() : null;
    }

    /** @param  iterable<int>  $rentalVehicleIds  @return Collection<int, MotrakVehicle> khoá = rental_vehicle_id */
    public function trackerMap(iterable $rentalVehicleIds): Collection
    {
        $ids = collect($rentalVehicleIds)->filter()->unique()->values();
        if (! $this->available() || $ids->isEmpty()) {
            return collect();
        }

        return MotrakVehicle::query()->whereIn('rental_vehicle_id', $ids)->get()->keyBy('rental_vehicle_id');
    }

    /** @return array{lat: float, lng: float}|null toạ độ chi nhánh nhận/trả của xe (categories.latitude/longitude) */
    public function branchCoordinates(Vehicle $vehicle): ?array
    {
        $c = Category::query()->withoutGlobalScopes()->whereKey($vehicle->branch_id)->first(['latitude', 'longitude']);
        if (! $c || $c->latitude === null || $c->longitude === null) {
            return null;
        }

        return ['lat' => (float) $c->latitude, 'lng' => (float) $c->longitude];
    }

    public function distanceToBranch(Vehicle $vehicle, ?float $lat, ?float $lng): ?int
    {
        $branch = $this->branchCoordinates($vehicle);
        if (! $branch || $lat === null || $lng === null) {
            return null;
        }

        return (int) round(MotrakCoordinates::distanceMeters($branch['lat'], $branch['lng'], $lat, $lng));
    }

    /** Thông tin GPS HIỆN TẠI của xe thuê (cho nhân viên). linked=false nếu chưa ghép thiết bị. */
    public function current(Vehicle $vehicle, ?MotrakVehicle $tracker = null): array
    {
        $tracker ??= $this->trackerFor($vehicle->id);
        if (! $tracker) {
            return ['linked' => false, 'message' => 'Xe chưa được ghép với thiết bị GPS.'];
        }

        $loc = MotrakTransformer::location($tracker);

        return [
            'linked'               => true,
            'tracker'              => ['motrak_vehicle_id' => $tracker->id, 'vehicle_id' => $tracker->vehicle_id, 'plate' => $tracker->plate, 'imei' => $tracker->imei],
            'has_position'         => $loc['has_position'],
            'connection_state'     => $loc['connection_state'],
            'connection_label'     => $loc['connection_label'],
            'is_moving'            => $loc['is_moving'],
            'speed_kmh'            => $loc['speed_kmh'],
            'heading_label'        => $loc['heading_label'],
            'position'             => $loc['has_position'] ? ['latitude' => $loc['latitude'], 'longitude' => $loc['longitude'], 'google_maps_url' => $loc['google_maps_url']] : null,
            'address'              => $loc['address'],
            'administrative_area'  => $loc['administrative_area'],
            'gps_time'             => $loc['gps_time'],
            'age_seconds'          => $loc['age_seconds'],
            'kmage'                => $tracker->kmage,
            'day_km'               => $tracker->day_km,
            'distance_to_branch_m' => $loc['has_position'] ? $this->distanceToBranch($vehicle, $tracker->latitude, $tracker->longitude) : null,
        ];
    }

    /**
     * Ảnh chụp GPS tại thời điểm giao/nhận xe (lưu vào biên bản). null nếu xe chưa ghép thiết bị hoặc chưa từng có vị trí.
     * fresh = điểm GPS còn mới (trong ngưỡng); điểm cũ vẫn được ghi (để biết vị trí cuối) nhưng không dùng đối chiếu km.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(Vehicle $vehicle): ?array
    {
        $t = $this->trackerFor($vehicle->id);
        if (! $t || ! $t->hasPosition()) {
            return null;
        }

        return [
            'gps_motrak_vehicle_id' => $t->id,
            'gps_latitude'          => $t->latitude,
            'gps_longitude'         => $t->longitude,
            'gps_kmage'             => $t->kmage,
            'gps_time'              => $t->gps_time,
            'gps_address'           => $t->address,
            'gps_fresh'             => ! $t->isStale(),
            'gps_branch_distance_m' => $this->distanceToBranch($vehicle, $t->latitude, $t->longitude),
        ];
    }

    /**
     * ĐIỂM GIAO XE = vị trí hiện tại của xe theo GPS. Xe chưa ghép GPS / chưa từng có vị trí thì rơi về chi nhánh của xe.
     * Trả về luôn có 'source' (vehicle = vị trí xe theo GPS | branch) và 'address'; toạ độ có thể null (chi nhánh chưa có toạ độ).
     *
     * @return array{source: string, address: ?string, latitude: ?float, longitude: ?float, located_at: ?string, fresh: ?bool, maps_url: ?string}
     */
    public function pickupPoint(Vehicle $vehicle): array
    {
        $t = $this->trackerFor($vehicle->id);
        if ($t && $t->hasPosition()) {
            $lat = (float) $t->latitude;
            $lng = (float) $t->longitude;

            return [
                'source'     => 'vehicle',
                'address'    => filled($t->address) ? (string) $t->address : null,
                'latitude'   => $lat,
                'longitude'  => $lng,
                'located_at' => $t->gps_time?->toIso8601String(),
                'fresh'      => ! $t->isStale(),
                'maps_url'   => 'https://www.google.com/maps?q=' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', ''),
            ];
        }

        $branch = Category::query()->withoutGlobalScopes()->whereKey($vehicle->branch_id)->first(['name', 'latitude', 'longitude']);
        $lat = $branch?->latitude !== null ? (float) $branch->latitude : null;
        $lng = $branch?->longitude !== null ? (float) $branch->longitude : null;

        return [
            'source'     => 'branch',
            'address'    => $branch?->name,
            'latitude'   => $lat,
            'longitude'  => $lng,
            'located_at' => null,
            'fresh'      => null,
            'maps_url'   => $lat !== null && $lng !== null ? 'https://www.google.com/maps?q=' . number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '') : null,
        ];
    }

    /** Nhắc nhân viên trước khi giao xe (không chặn giao): GPS chưa có vị trí / dữ liệu quá cũ. */
    public function pickupWarnings(Vehicle $vehicle): array
    {
        $t = $this->trackerFor($vehicle->id);
        if (! $t) {
            return [];
        }
        if (! $t->hasPosition()) {
            return ['GPS của xe chưa có dữ liệu vị trí.'];
        }
        if ($t->isStale()) {
            return ['Dữ liệu GPS của xe đã cũ ' . (int) round(($t->ageSeconds() ?? 0) / 60) . ' phút (thiết bị có thể mất tín hiệu hoặc bị ngắt nguồn).'];
        }

        // Điểm giao xe là vị trí hiện tại của xe nên không còn so với chi nhánh.
        return [];
    }

    /**
     * So sánh km GPS (kmage nhận − kmage giao) với km odometer.
     *
     * @return array{gps_km: ?float, odometer_km: ?int, discrepancy_km: ?float, discrepancy_pct: ?float, reliable: bool, flagged: bool}|null null nếu chưa có đủ dữ liệu
     */
    public function kmComparison(VehicleBooking $booking): ?array
    {
        $pickup = $booking->handover(VehicleHandover::TYPE_PICKUP);
        $return = $booking->handover(VehicleHandover::TYPE_RETURN);
        if (! $pickup || ! $return || $pickup->gps_kmage === null || $return->gps_kmage === null) {
            return null;
        }

        $gpsKm = round($return->gps_kmage - $pickup->gps_kmage, 3);
        $odo = (int) ($booking->km_driven ?? max(0, $return->odometer_km - $pickup->odometer_km));
        $reliable = $pickup->gps_fresh && $return->gps_fresh && $gpsKm >= 0 && $gpsKm < 5000;
        $diff = round(abs($gpsKm - $odo), 3);
        $pct = round($diff / max($odo, 1) * 100, 2);

        return [
            'gps_km'          => $gpsKm,
            'odometer_km'     => $odo,
            'discrepancy_km'  => $diff,
            'discrepancy_pct' => $pct,
            'reliable'        => $reliable,
            'flagged'         => $reliable && $diff >= (int) $this->cfg('km_discrepancy_min_km', 10) && $pct >= (float) $this->cfg('km_discrepancy_pct', 10),
        ];
    }

    /**
     * Hành trình theo đơn thuê: các điểm GPS đã lưu trong [giao xe − lề, nhận xe (hoặc bây giờ) + lề] + tóm tắt.
     *
     * @return array<string, mixed>
     */
    public function track(VehicleBooking $booking): array
    {
        $tracker = $this->trackerFor($booking->vehicle_id);
        if (! $tracker) {
            return ['available' => false, 'reason' => 'Xe chưa được ghép với thiết bị GPS.', 'note' => self::COVERAGE_NOTE];
        }
        if ($booking->actual_start_at === null) {
            return ['available' => false, 'reason' => 'Xe chưa được giao cho khách nên chưa có hành trình.', 'note' => self::COVERAGE_NOTE];
        }

        $margin = (int) $this->cfg('track_margin_minutes', 5);
        $from = $booking->actual_start_at->copy()->subMinutes($margin);
        $to = ($booking->actual_end_at ?? now())->copy()->addMinutes($margin);

        $rows = MotrakVehicleLocation::query()->where('motrak_vehicle_id', $tracker->id)
            ->whereBetween('gps_time', [$from, $to])->orderBy('gps_time')->orderBy('id')->limit(5000)->get();

        $distance = 0.0;
        $prev = null;
        foreach ($rows as $p) {
            if ($prev) {
                $distance += MotrakCoordinates::distanceMeters($prev->latitude, $prev->longitude, $p->latitude, $p->longitude);
            }
            $prev = $p;
        }

        $cmp = $this->kmComparison($booking);
        $first = $rows->first();
        $last = $rows->last();

        return [
            'available' => true,
            'window'    => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'summary'   => [
                'point_count'      => $rows->count(),
                'path_distance_km' => round($distance / 1000, 2),
                'gps_km'           => $cmp['gps_km'] ?? ($first && $last && $first->kmage !== null && $last->kmage !== null ? round($last->kmage - $first->kmage, 3) : null),
                'max_speed_kmh'    => $rows->max('speed_kmh'),
                'areas'            => $rows->pluck('administrative_area')->filter()->unique()->values()->all(),
                'first_point'      => $first ? MotrakTransformer::point($first) : null,
                'last_point'       => $last ? MotrakTransformer::point($last) : null,
            ],
            'km_comparison' => $cmp,
            'points'        => $rows->map(fn (MotrakVehicleLocation $p) => MotrakTransformer::point($p))->values()->all(),
            'note'          => self::COVERAGE_NOTE,
        ];
    }

    /** Khối GPS gọn cho chi tiết đơn (API + CMS): vị trí hiện tại, so sánh km, ảnh chụp lúc giao/nhận, cảnh báo, nhắc trước khi giao. */
    public function bookingSummary(VehicleBooking $booking): array
    {
        $vehicle = $booking->vehicle;
        if (! $vehicle) {
            return ['linked' => false];
        }
        $tracker = $this->trackerFor($vehicle->id);
        if (! $tracker) {
            return ['linked' => false, 'message' => 'Xe chưa được ghép với thiết bị GPS.'];
        }

        $snap = fn (?VehicleHandover $h) => $h && $h->gps_motrak_vehicle_id ? [
            'latitude' => $h->gps_latitude, 'longitude' => $h->gps_longitude, 'google_maps_url' => MotrakCoordinates::googleMapsUrl($h->gps_latitude, $h->gps_longitude),
            'kmage' => $h->gps_kmage, 'gps_time' => $h->gps_time?->toIso8601String(), 'address' => $h->gps_address, 'fresh' => $h->gps_fresh,
            'branch_distance_m' => $h->gps_branch_distance_m,
        ] : null;

        return [
            'linked'       => true,
            'current'      => $this->current($vehicle, $tracker),
            'pickup'       => $snap($booking->handover(VehicleHandover::TYPE_PICKUP)),
            'return'       => $snap($booking->handover(VehicleHandover::TYPE_RETURN)),
            'km_comparison' => $this->kmComparison($booking),
            'pickup_warnings' => $booking->status === VehicleBooking::STATUS_CONFIRMED ? $this->pickupWarnings($vehicle) : [],
            'open_alerts'  => VehicleGpsAlert::query()->where('booking_id', $booking->id)->whereNull('acknowledged_at')->count(),
        ];
    }
}
