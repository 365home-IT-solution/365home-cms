<?php

namespace Modules\Motrak\App\Support;

/**
 * Toạ độ Motrak: x = kinh độ × 1.000.000, y = vĩ độ × 1.000.000 (đúng thứ tự: x → longitude, y → latitude). Không bao giờ đảo.
 * Toạ độ không hợp lệ (null, không phải số, (0,0), ngoài miền ±90/±180) trả null — tuyệt đối không dùng làm vị trí.
 */
class MotrakCoordinates
{
    public const SCALE = 1000000;

    /** @return array{lat: float, lng: float}|null */
    public static function fromRaw(mixed $x, mixed $y): ?array
    {
        if ($x === null || $y === null || ! is_numeric($x) || ! is_numeric($y)) {
            return null;
        }

        $lng = (float) $x / self::SCALE;
        $lat = (float) $y / self::SCALE;

        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => round($lat, 7), 'lng' => round($lng, 7)];
    }

    /** https://www.google.com/maps?q=LATITUDE,LONGITUDE (6 chữ số thập phân, dấu chấm, không dùng ký hiệu khoa học). */
    public static function googleMapsUrl(float|string|null $lat, float|string|null $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return 'https://www.google.com/maps?q=' . number_format((float) $lat, 6, '.', '') . ',' . number_format((float) $lng, 6, '.', '');
    }

    /** Khoảng cách đường chim bay (mét) giữa hai điểm — công thức haversine. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
