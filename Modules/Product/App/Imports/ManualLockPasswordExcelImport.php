<?php

declare(strict_types=1);

namespace Modules\Product\App\Imports;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Models\Product;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Parses Excel theo cấu trúc:
 *
 *   A            B           C           D ...
 *   Tên phòng    01/06/2026  02/06/2026  03/06/2026
 *   minimal      323142      314231      431421
 *   wabisabi     113524      352421      435242
 *   mediterran   234131      413122      441312
 *   rustic       441324      132415      113245
 *   Pass Cổng    724221#     241414#     567764#
 *
 * Mỗi ô (room × date) → 1 ManualLockPassword record.
 */
class ManualLockPasswordExcelImport
{
    public function __construct(
        private readonly int    $categoryId,
        private readonly string $validFromTime,   // "HH:MM"
        private readonly string $validUntilTime,  // "HH:MM"
        private readonly string $matchBy,         // 'name' | 'slug'
        private readonly bool   $skipExisting,
    ) {}

    /**
     * @return array{created:int, skipped:int, not_found:list<string>, errors:list<string>}
     */
    public function handle(string $absolutePath): array
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $rows        = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if (empty($rows)) {
            return ['created' => 0, 'skipped' => 0, 'not_found' => [], 'errors' => ['File rỗng hoặc không đọc được']];
        }

        // --- Phân tích header (row 0) ---
        $header = $rows[0];
        $dateColumns = $this->parseDateColumns($header);

        if (empty($dateColumns)) {
            return ['created' => 0, 'skipped' => 0, 'not_found' => [], 'errors' => ['Không tìm thấy cột ngày hợp lệ trong dòng tiêu đề']];
        }

        // --- Phân tách dòng phòng vs dòng Pass Cổng ---
        // Dòng cuối cùng (hàng 6) luôn là Pass Cổng; các dòng còn lại (2→5) là phòng.
        $dataRows = [];
        for ($i = 1; $i < count($rows); $i++) {
            $cellA = trim((string) ($rows[$i][0] ?? ''));
            if ($cellA === '') continue;
            $dataRows[] = $rows[$i];
        }

        $gateRow  = ! empty($dataRows) ? array_pop($dataRows) : null;
        $roomRows = $dataRows;

        $created  = 0;
        $skipped  = 0;
        $notFound = [];
        $errors   = [];

        [$fromH, $fromM]  = $this->parseTime($this->validFromTime);
        [$untilH, $untilM] = $this->parseTime($this->validUntilTime);

        foreach ($roomRows as $roomRow) {
            $roomName = trim((string) ($roomRow[0] ?? ''));
            if ($roomName === '') continue;

            $product = $this->findProduct($roomName);
            if (! $product) {
                if (! in_array($roomName, $notFound, true)) {
                    $notFound[] = $roomName;
                }
            }

            foreach ($dateColumns as $col => $date) {
                $roomPassword = trim((string) ($roomRow[$col] ?? ''));
                $gatePassword = $gateRow ? trim((string) ($gateRow[$col] ?? '')) : '';

                // Cần ít nhất gate_password
                if ($gatePassword === '') continue;

                $validFrom  = $date->copy()->setTime($fromH, $fromM);
                $validUntil = $date->copy()->addDay()->setTime($untilH, $untilM);

                if ($this->skipExisting && $this->recordExists($gatePassword, $validFrom, $product)) {
                    $skipped++;
                    continue;
                }

                try {
                    $record = ManualLockPassword::create([
                        'name'          => $roomName . ' – ' . $date->format('d/m/Y'),
                        'gate_password' => $gatePassword,
                        'room_password' => $roomPassword !== '' ? $roomPassword : null,
                        'category_id'   => $this->categoryId,
                        'valid_from'    => $validFrom,
                        'valid_until'   => $validUntil,
                        'is_active'     => true,
                    ]);

                    if ($product) {
                        $record->products()->attach($product->id);
                    }

                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = "{$roomName} [{$date->format('d/m/Y')}]: {$e->getMessage()}";
                }
            }
        }

        return compact('created', 'skipped', 'notFound', 'errors');
    }

    // -------------------------------------------------------------------------

    private function parseDateColumns(array $header): array
    {
        $columns = [];

        for ($col = 1; $col < count($header); $col++) {
            $raw = $header[$col];
            if ($raw === null || $raw === '') continue;

            try {
                // Ô chứa số → Excel serial date (số nguyên/thập phân)
                if (is_numeric($raw)) {
                    $dt = ExcelDate::excelToDateTimeObject((float) $raw);
                    $columns[$col] = Carbon::instance($dt)->startOfDay();
                } else {
                    // Ưu tiên định dạng Việt Nam d/m/Y trước, rồi mới fallback
                    $str = trim((string) $raw);
                    $parsed = false;
                    foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'Y-m-d'] as $fmt) {
                        try {
                            $c = Carbon::createFromFormat($fmt, $str);
                            if ($c !== false) { $parsed = $c->startOfDay(); break; }
                        } catch (\Throwable) {}
                    }
                    $columns[$col] = $parsed ?: Carbon::parse($str)->startOfDay();
                }
            } catch (\Throwable) {
                // Bỏ qua cột không parse được
            }
        }

        return $columns;
    }

    private function findProduct(string $roomName): ?Product
    {
        if ($this->matchBy === 'slug') {
            return Product::where('slug', Str::slug($roomName))->first();
        }

        // Khớp tên (không phân biệt hoa thường, trim)
        return Product::whereRaw('LOWER(TRIM(name)) LIKE ?', ['%' . mb_strtolower($roomName) . '%'])
            ->first();
    }

    private function recordExists(string $gatePassword, Carbon $validFrom, ?Product $product): bool
    {
        $query = ManualLockPassword::where('gate_password', $gatePassword)
            ->where('category_id', $this->categoryId)
            ->whereDate('valid_from', $validFrom->toDateString());

        if ($product) {
            $query->whereHas('products', fn ($q) => $q->where('products.id', $product->id));
        }

        return $query->exists();
    }

    private function parseTime(string $time): array
    {
        $parts = explode(':', $time);
        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }
}
