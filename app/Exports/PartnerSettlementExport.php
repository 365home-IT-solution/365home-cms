<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StylesSettlementSheet;
use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Services\SettlementService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Xuất Excel bảng đối soát của 1 đối tác: sheet "Tổng hợp" (số liệu kỳ) + sheet "Chi tiết đơn" (từng đơn trong kỳ).
 * Số liệu lấy từ SettlementService::toArray() — cùng nguồn với màn hình/API nên file luôn khớp với bảng đối soát.
 * Dùng chung cho nút "Xuất Excel" ở CMS và API GET …/settlements/{id}/export.
 */
class PartnerSettlementExport implements WithMultipleSheets
{
    public function __construct(private readonly PartnerSettlement $settlement) {}

    public static function fileName(PartnerSettlement $settlement): string
    {
        return 'doi-soat-' . $settlement->code . '.xlsx';
    }

    public function sheets(): array
    {
        $data = app(SettlementService::class)->toArray($this->settlement, withOrders: true);
        $partner = Partner::withTrashed()->find($this->settlement->partner_id);

        return [
            new SettlementSummarySheet($data, $partner),
            new SettlementOrdersSheet($data, $partner),
        ];
    }
}

/** Sheet 1 — "Tổng hợp": thông tin chung, số liệu, kết quả, hoá đơn. */
class SettlementSummarySheet implements FromArray, WithEvents, WithTitle
{
    use StylesSettlementSheet;

    public function __construct(private readonly array $d, private readonly ?Partner $partner) {}

    public function title(): string
    {
        return 'Tổng hợp';
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->build($e->sheet->getDelegate())];
    }

    private static function money(?int $v): int
    {
        return (int) ($v ?? 0);
    }

    private static function date(?string $iso, string $format = 'd/m/Y'): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format($format) : '';
    }

    private function build(Worksheet $ws): void
    {
        $d = $this->d;
        $ws->getColumnDimension('A')->setWidth(46);
        $ws->getColumnDimension('B')->setWidth(42);

        $period = self::date($d['period_start']) . ' – ' . self::date($d['period_end']);
        $row = $this->titleBlock($ws, 2, 'BẢNG ĐỐI SOÁT HOA HỒNG', [
            'Mã bảng: ' . $d['code'] . '  |  Kỳ: ' . $period,
        ]);

        // Thông tin chung (chỉ các dòng có giá trị)
        $info = array_values(array_filter([
            ['Đối tác', $this->partner?->legal_name ?: $this->partner?->name],
            ['Trạng thái', $d['status_label']],
            $d['sent_at'] ? ['Ngày gửi đối tác', self::date($d['sent_at'], 'd/m/Y H:i')] : null,
            $d['due_at'] ? ['Hạn nộp', self::date($d['due_at'])] : null,
        ]));
        $row = $this->pairs($ws, $row, $info);

        // Tỉ lệ hoa hồng hiện trong nhãn khi mọi đơn cùng 1 tỉ lệ (đơn miễn hoa hồng không tính).
        $rates = collect($d['orders'])->reject(fn ($o) => $o['commission_waived'] || $o['commission_rate'] === null)
            ->pluck('commission_rate')->map(fn ($r) => (float) $r)->unique()->values();
        $rateLabel = $rates->count() === 1
            ? ' (' . rtrim(rtrim(number_format($rates[0], 2, ',', ''), '0'), ',') . '%)'
            : '';

        // Số liệu — dòng "hoàn thay" chỉ hiện khi khác 0 (có ảnh hưởng đến kết quả)
        $row = $this->section($ws, $row + 1, 'SỐ LIỆU ĐỐI SOÁT');
        $numbers = array_values(array_filter([
            ['Số đơn trong kỳ', (int) $d['orders_count'], false],
            ['Doanh thu các đơn', self::money($d['revenue_total']), true],
            ['Hoa hồng' . $rateLabel, self::money($d['commission_total']), true],
            ['365home bù khuyến mãi', self::money($d['subsidy_total']), true],
            ['365home thu hộ', self::money($d['platform_collected_total']), true],
            self::money($d['platform_refunded_total']) !== 0
                ? ['365home hoàn thay cho khách', self::money($d['platform_refunded_total']), true]
                : null,
        ]));
        foreach ($numbers as [$label, $value, $isMoney]) {
            $ws->setCellValue("A{$row}", $label);
            $ws->setCellValue("B{$row}", $value);
            $ws->getStyle("B{$row}")->getNumberFormat()->setFormatCode($isMoney ? self::MONEY_FORMAT_VND : '0');
            $ws->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->border($ws, "A{$row}:B{$row}");
            $this->soft($ws, "A{$row}");
            $row++;
        }

        // Kết quả
        $row = $this->section($ws, $row + 1, 'KẾT QUẢ ĐỐI SOÁT');
        $net = self::money($d['net_amount']);
        $text = $net > 0
            ? 'Đối tác nộp cho 365home: ' . number_format($net, 0, ',', '.') . ' đ'
            : ($net < 0 ? '365home chi cho đối tác: ' . number_format(-$net, 0, ',', '.') . ' đ' : 'Hai bên không còn khoản phải nộp hay chi');

        $ws->mergeCells("A{$row}:B{$row}");
        $ws->setCellValue("A{$row}", $text);
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13)->getColor()->setRGB(self::COLOR_PRIMARY);
        $ws->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
        $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension($row)->setRowHeight(30);
        $this->border($ws, "A{$row}:B{$row}");
        $row++;

        // Hoá đơn / ghi chú: chỉ hiện khi có
        $extra = array_values(array_filter([
            $d['invoice_no'] ? ['Số hoá đơn GTGT', $d['invoice_no']] : null,
            $d['note'] ? ['Ghi chú', $d['note']] : null,
        ]));
        if ($extra !== []) {
            $row = $this->pairs($ws, $row + 1, $extra);
        }

        $this->printSetup($ws, landscape: false);
    }

    private function section(Worksheet $ws, int $row, string $label): int
    {
        $ws->mergeCells("A{$row}:B{$row}");
        $ws->setCellValue("A{$row}", $label);
        $style = $ws->getStyle("A{$row}:B{$row}");
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_PRIMARY);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension($row)->setRowHeight(22);

        return $row + 1;
    }

    /** @param array<int, array{0: string, 1: mixed}> $pairs */
    private function pairs(Worksheet $ws, int $row, array $pairs): int
    {
        foreach ($pairs as [$label, $value]) {
            $ws->setCellValue("A{$row}", $label);
            $ws->setCellValue("B{$row}", $value === null ? '' : (string) $value);
            $ws->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setWrapText(true);
            $this->border($ws, "A{$row}:B{$row}");
            $this->soft($ws, "A{$row}");
            $row++;
        }

        return $row;
    }
}

/** Sheet 2 — "Chi tiết đơn": mỗi đơn 1 dòng, có dòng TỔNG CỘNG. */
class SettlementOrdersSheet implements FromArray, WithEvents, WithTitle
{
    use StylesSettlementSheet;

    private const HEADERS = [
        'STT', 'Mã đơn', 'Khách đặt', 'Phòng', 'Nhận phòng', 'Trả phòng', 'Người thu tiền',
        'Doanh thu đối tác giữ (đ)', '365home đã thu hộ (đ)', 'Tỉ lệ hoa hồng', 'Hoa hồng (đ)', '365home bù (đ)',
        'Khoản giảm giá', 'Ngày chốt',
    ];

    private const WIDTHS = [6, 18, 24, 40, 14, 14, 17, 17, 17, 11, 15, 14, 44, 12];

    public function __construct(private readonly array $d, private readonly ?Partner $partner) {}

    public function title(): string
    {
        return 'Chi tiết đơn';
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->build($e->sheet->getDelegate())];
    }

    private static function money($v): int
    {
        return (int) ($v ?? 0);
    }

    private static function fmtDate(?string $iso): string
    {
        return $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y') : '';
    }

    private function build(Worksheet $ws): void
    {
        $d = $this->d;
        $cols = count(self::HEADERS);

        foreach (self::WIDTHS as $i => $w) {
            $ws->getColumnDimension(self::col($i + 1))->setWidth($w);
        }

        $period = self::fmtDate($d['period_start']) . ' – ' . self::fmtDate($d['period_end']);
        $headerRow = $this->titleBlock($ws, $cols, 'CHI TIẾT ĐƠN TRONG KỲ ĐỐI SOÁT', [
            'Mã bảng: ' . $d['code'] . '  |  Kỳ: ' . $period . '  |  Đối tác: ' . ($this->partner?->legal_name ?: $this->partner?->name),
        ]);

        foreach (self::HEADERS as $i => $label) {
            $ws->setCellValue(self::col($i + 1) . $headerRow, $label);
        }
        $this->headerRow($ws, $headerRow, 1, $cols);

        $row = $headerRow + 1;
        $first = $row;

        foreach ($d['orders'] as $i => $o) {
            $rooms = collect($o['rooms']);
            $discounts = collect($o['discounts'])->map(function ($line) {
                $who = match ($line['funded_by'] ?? 'partner') {
                    'platform' => '365home chịu',
                    'shared'   => 'hai bên cùng chịu',
                    default    => 'đối tác chịu',
                };
                $name = $line['code'] ?: match ($line['source'] ?? '') {
                    'promotion'  => 'Khuyến mãi phòng',
                    'system'     => 'Chiết khấu hệ thống',
                    'membership' => 'Ưu đãi hạng thành viên',
                    default      => 'Giảm giá',
                };

                return $name . ': ' . number_format((int) ($line['amount'] ?? 0), 0, ',', '.') . ' đ (' . $who . ')';
            })->implode("\n");

            $values = [
                $i + 1,
                $o['order_code'],
                $o['buyer_name'],
                $rooms->pluck('name')->filter()->implode("\n"),
                $rooms->map(fn ($r) => self::fmtDate($r['checkin_date'] ?? null))->filter()->implode("\n"),
                $rooms->map(fn ($r) => self::fmtDate($r['checkout_date'] ?? null))->filter()->implode("\n"),
                $o['collected_by_label'] ?? $o['collected_by'],
                self::money($o['revenue']),
                self::money($o['platform_collected']),
                $o['commission_waived'] ? 0 : ($o['commission_rate'] ?? 0),
                self::money($o['commission_amount']),
                self::money($o['platform_subsidy']),
                $discounts,
                $o['finalized_at'] ? Date::PHPToExcel(Carbon::parse($o['finalized_at'])->timezone(config('app.timezone'))->startOfDay()) : '',
            ];

            foreach ($values as $c => $v) {
                $cell = self::col($c + 1) . $row;
                is_int($v) || is_float($v)
                    ? $ws->setCellValueExplicit($cell, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC)
                    : $ws->setCellValueExplicit($cell, (string) ($v ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $row++;
        }

        $last = $row - 1;

        if ($last >= $first) {
            $body = 'A' . $first . ':' . self::col($cols) . $last;
            $this->border($ws, $body);
            $ws->getStyle($body)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $ws->getStyle("A{$first}:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("E{$first}:G{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("H{$first}:I{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $ws->getStyle("K{$first}:L{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            // Tỉ lệ hoa hồng: số nguyên hiện "20%", lẻ hiện "12.5%" (lưu dạng phần trăm thật của Excel).
            for ($r = $first; $r <= $last; $r++) {
                $rate = (float) $ws->getCell("J{$r}")->getValue();
                $ws->setCellValueExplicit("J{$r}", $rate / 100, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $ws->getStyle("J{$r}")->getNumberFormat()->setFormatCode(fmod($rate, 1.0) === 0.0 ? '0%' : '0.0%');
            }
            $ws->getStyle("J{$first}:J{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("N{$first}:N{$last}")->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
            $ws->getStyle("N{$first}:N{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Xen kẽ màu dòng cho dễ đọc
            for ($r = $first; $r <= $last; $r++) {
                if (($r - $first) % 2 === 1) {
                    $this->soft($ws, "A{$r}:" . self::col($cols) . $r);
                }
            }
        }

        // Dòng tổng cộng
        $ws->mergeCells("A{$row}:G{$row}");
        $ws->setCellValue("A{$row}", 'TỔNG CỘNG (' . count($d['orders']) . ' đơn)');
        $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        foreach (['H', 'I', 'K', 'L'] as $c) {
            $ws->setCellValue("{$c}{$row}", $last >= $first ? "=SUM({$c}{$first}:{$c}{$last})" : 0);
            $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }
        $this->totalRow($ws, $row, 1, $cols);

        $ws->freezePane('D' . ($headerRow + 1));

        if ($last >= $first) {
            $ws->setAutoFilter('A' . $headerRow . ':' . self::col($cols) . $last);
        }

        $this->printSetup($ws, landscape: true, repeatHeaderRow: $headerRow);
    }
}
