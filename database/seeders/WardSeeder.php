<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class WardSeeder extends Seeder
{
    private const API_BASE = 'https://provinces.open-api.vn/api/v2';

    // 34 mã tỉnh/thành phố theo hệ thống 2025
    private const PROVINCE_CODES = [
        1, 4, 8, 11, 12, 14, 15, 19, 20, 22, 24, 25,
        31, 33, 37, 38, 40, 42, 44, 46, 48, 51, 52, 56,
        66, 68, 75, 79, 80, 82, 86, 91, 92, 96,
    ];

    public function run(): void
    {
        $this->command->info('Đang lấy dữ liệu phường/xã từ provinces.open-api.vn...');

        Ward::truncate();

        $provinceCodes = DB::table('provinces')
            ->whereNotNull('code')
            ->pluck('code')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        if (empty($provinceCodes)) {
            $provinceCodes = self::PROVINCE_CODES;
            $this->command->warn('Bảng provinces chưa có code, dùng danh sách mặc định 34 tỉnh.');
        }

        $total = 0;

        foreach ($provinceCodes as $provinceCode) {
            $response = Http::timeout(15)->get(self::API_BASE . '/w/', [
                'province' => $provinceCode,
            ]);

            if (! $response->successful()) {
                $this->command->warn("Không lấy được dữ liệu cho tỉnh {$provinceCode}, bỏ qua.");
                continue;
            }

            $wards = $response->json();

            if (empty($wards)) {
                continue;
            }

            $rows = array_map(fn ($w) => [
                'code'          => $w['code'],
                'name'          => $w['name'],
                'division_type' => $w['division_type'],
                'codename'      => $w['codename'],
                'province_code' => $w['province_code'],
                'created_at'    => now(),
                'updated_at'    => now(),
            ], $wards);

            DB::table('wards')->insertOrIgnore($rows);

            $count = count($rows);
            $total += $count;
            $this->command->line("  Tỉnh {$provinceCode}: {$count} phường/xã");
        }

        $this->command->info("Hoàn tất! Đã seed {$total} phường/xã.");
    }
}
