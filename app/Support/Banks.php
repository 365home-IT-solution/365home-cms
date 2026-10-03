<?php

declare(strict_types=1);

namespace App\Support;

// Danh sách ngân hàng chuẩn (config/banks.php) — dùng cho ô chọn ngân hàng, kiểm tra dữ liệu và API /api/v2/banks.
class Banks
{
    /** @return array<string, array{short_name: string, name: string, bin: string}> */
    public static function all(): array
    {
        return config('banks.list', []);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /** @return list<string> */
    public static function shortNames(): array
    {
        return array_values(array_map(fn (array $b) => $b['short_name'], self::all()));
    }

    public static function find(?string $code): ?array
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' && isset(self::all()[$code]) ? ['code' => $code] + self::all()[$code] : null;
    }

    /** Tìm theo tên hiển thị đã lưu trong hồ sơ (bank_name). */
    public static function findByShortName(?string $shortName): ?array
    {
        foreach (self::all() as $code => $bank) {
            if (mb_strtolower($bank['short_name']) === mb_strtolower(trim((string) $shortName))) {
                return ['code' => $code] + $bank;
            }
        }

        return null;
    }

    /** Cho Filament Select: [short_name => "Vietcombank — Ngân hàng TMCP Ngoại thương Việt Nam"]. */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $bank) {
            $out[$bank['short_name']] = $bank['short_name'] . ' — ' . $bank['name'];
        }

        return $out;
    }
}
