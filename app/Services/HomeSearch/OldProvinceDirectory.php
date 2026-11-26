<?php

declare(strict_types=1);

namespace App\Services\HomeSearch;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Danh sách 63 tỉnh/thành CŨ (trước sáp nhập 07/2025) + các "thành phố thuộc tỉnh" (Đà Lạt, Vũng
 * Tàu, Phan Thiết...) lấy từ https://provinces.open-api.vn/api/v1/ — dùng cho màn "Bạn muốn đi
 * đâu?" của ô tìm kiếm trang chủ mobile. Khách vẫn quen gọi điểm đến theo tên cũ, còn bảng
 * provinces của hệ thống đã là 34 tỉnh mới, nên danh sách hiển thị lấy riêng từ API này.
 *
 * Dữ liệu gần như không đổi: cache 30 ngày, kèm 1 bản "lần lấy thành công gần nhất" không hết hạn
 * để API bên ngoài có lỗi/timeout thì vẫn còn dữ liệu cũ dùng tiếp.
 */
final class OldProvinceDirectory
{
    private const SOURCE_URL = 'https://provinces.open-api.vn/api/v1/';

    private const CACHE_KEY = 'home-search:old-provinces:v1';

    private const LAST_GOOD_KEY = 'home-search:old-provinces:v1:last-good';

    /**
     * @return array<int, array{code:int, name:string, short_name:string, kind:string, province_code:int, province_name:string}>
     *   kind: 'province' (tỉnh/thành phố trung ương) | 'city' (thành phố thuộc tỉnh)
     */
    public function all(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $rows = $this->fetch();

        if ($rows !== []) {
            Cache::put(self::CACHE_KEY, $rows, now()->addDays(30));
            Cache::forever(self::LAST_GOOD_KEY, $rows);

            return $rows;
        }

        // API lỗi: dùng bản tốt gần nhất, giữ trong cache ngắn để không gọi lại API mỗi request.
        $fallback = Cache::get(self::LAST_GOOD_KEY, []);
        Cache::put(self::CACHE_KEY, $fallback, now()->addMinutes(10));

        return is_array($fallback) ? $fallback : [];
    }

    private function fetch(): array
    {
        try {
            $response = Http::timeout(8)->acceptJson()->get(self::SOURCE_URL, ['depth' => 2]);
            if (! $response->successful() || ! is_array($response->json())) {
                Log::warning('OldProvinceDirectory: provinces.open-api.vn trả lỗi', ['status' => $response->status()]);

                return [];
            }

            return $this->normalize($response->json());
        } catch (\Throwable $e) {
            Log::warning('OldProvinceDirectory: không gọi được provinces.open-api.vn', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function normalize(array $provinces): array
    {
        $rows = [];

        foreach ($provinces as $province) {
            if (! isset($province['code'], $province['name'])) {
                continue;
            }

            $provinceShort = self::shortName((string) $province['name']);

            $rows[] = [
                'code'          => (int) $province['code'],
                'name'          => (string) $province['name'],
                'short_name'    => $provinceShort,
                'kind'          => 'province',
                'province_code' => (int) $province['code'],
                'province_name' => $provinceShort,
            ];

            foreach ($province['districts'] ?? [] as $district) {
                // Chỉ "thành phố thuộc tỉnh" — quận/huyện/thị xã quá vụn cho danh sách điểm đến.
                if (($district['division_type'] ?? '') !== 'thành phố' || ! isset($district['code'], $district['name'])) {
                    continue;
                }

                // TP trùng tên tỉnh (TP Thái Nguyên, TP Hà Tĩnh...) — dòng tỉnh đã đại diện rồi.
                $cityShort = self::shortName((string) $district['name']);
                if (mb_strtolower($cityShort) === mb_strtolower($provinceShort)) {
                    continue;
                }

                $rows[] = [
                    'code'          => (int) $district['code'],
                    'name'          => (string) $district['name'],
                    'short_name'    => $cityShort,
                    'kind'          => 'city',
                    'province_code' => (int) $province['code'],
                    'province_name' => $provinceShort,
                ];
            }
        }

        return $rows;
    }

    public static function shortName(string $name): string
    {
        return trim((string) preg_replace('/^(Thành phố|Tỉnh)\s+/u', '', trim($name)));
    }
}
