<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StylesSettlementSheet;
use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * BẢNG ĐỐI SOÁT CỦA TẤT CẢ ĐỐI TÁC TRONG 1 CHU KỲ (chỉ Super Admin): 1 sheet, mỗi đối tác 1 dòng cho kỳ đã chọn.
 * Chỉ gồm đối tác CÓ bảng trong kỳ; đối tác chưa có bảng không xuất hiện.
 * Bảng được tính vào kỳ khi nằm TRỌN trong khoảng [từ ngày, đến ngày] — kỳ 2 lần/tháng chọn đúng 01–14 hoặc 15–cuối tháng,
 * kỳ tháng chọn cả tháng (khi đó đối tác 2 lần/tháng được cộng cả 2 bảng).
 *
 * Dùng chung cho API GET /api/admin/settlements/cycle/export và nút "Xuất Excel theo chu kỳ" ở CMS.
 */
class CycleSettlementExport implements FromArray, WithEvents, WithTitle
{
    use StylesSettlementSheet;

    private const HEADERS = [
        'STT', 'Đối tác', 'Mã bảng', 'Trạng thái', 'Số đơn', 'Doanh thu (đ)', 'Hoa hồng (đ)', '365home bù (đ)',
        '365home thu hộ (đ)', '365home hoàn thay (đ)', 'Kết quả (đ)', 'Chiều thanh toán',
    ];

    private const WIDTHS = [6, 34, 30, 22, 9, 17, 15, 14, 18, 17, 16, 25];

    /** @var Collection<int, array<string, mixed>> */
    private Collection $rows;

    public function __construct(private readonly Carbon $start, private readonly Carbon $end, Collection $rows)
    {
        $this->rows = $rows;
    }

    public function title(): string
    {
        return 'Đối soát theo chu kỳ';
    }

    public function array(): array
    {
        return [];
    }

    public static function fileName(Carbon $start, Carbon $end): string
    {
        return 'doi-soat-chu-ky-' . $start->format('Ymd') . '-' . $end->format('Ymd') . '.xlsx';
    }

    /**
     * @param  array{partner_ids?: array<int, string>, only_with_sheet?: bool}  $filters
     */
    public static function for(User $user, Carbon $start, Carbon $end, array $filters = []): self
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $sheets = PartnerSettlement::query()
            ->whereDate('period_start', '>=', $start->toDateString())
            ->whereDate('period_end', '<=', $end->toDateString())
            ->when(! $user->seesAllPartners(), fn ($q) => $q->where('partner_id', $user->partner_id ?? '-'))
            ->when(! empty($filters['partner_ids']) && $user->seesAllPartners(), fn ($q) => $q->whereIn('partner_id', $filters['partner_ids']))
            ->orderBy('period_start')->orderBy('id')
            ->get();

        // Chỉ đối tác có bảng trong kỳ.
        $partners = Partner::query()
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->where('is_platform_partner', false)
            ->whereIn('id', $sheets->pluck('partner_id'))
            ->when(! $user->seesAllPartners(), fn ($q) => $q->where('id', $user->partner_id ?? '-'))
            ->when(! empty($filters['partner_ids']) && $user->seesAllPartners(), fn ($q) => $q->whereIn('id', $filters['partner_ids']))
            ->get(['id', 'name', 'legal_name'])
            ->reject(fn (Partner $p) => $p->isSystemPartner())
            ->sortBy(fn (Partner $p) => mb_strtolower((string) ($p->legal_name ?: $p->name)))
            ->values();

        $byPartner = $sheets->groupBy('partner_id');

        $rows = $partners->map(function (Partner $p) use ($byPartner) {
            /** @var Collection<int, PartnerSettlement> $own */
            $own = $byPartner->get($p->id, collect());

            return [
                'name'       => $p->legal_name ?: $p->name,
                'has_sheet'  => $own->isNotEmpty(),
                'codes'      => $own->pluck('code')->implode(', '),
                'statuses'   => $own->pluck('status')->unique()->map(fn ($s) => PartnerSettlement::STATUSES[$s] ?? $s)->implode(', '),
                'orders'     => (int) $own->sum('orders_count'),
                'revenue'    => (int) $own->sum('revenue_total'),
                'commission' => (int) $own->sum('commission_total'),
                'subsidy'    => (int) $own->sum('subsidy_total'),
                'collected'  => (int) $own->sum('platform_collected_total'),
                'refunded'   => (int) $own->sum('platform_refunded_total'),
                'net'        => (int) $own->sum('net_amount'),
            ];
        });

        return new self($start, $end, $rows);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->build($e->sheet->getDelegate())];
    }

    private static function direction(int $net, bool $hasSheet): string
    {
        if (! $hasSheet) {
            return '';
        }

        return $net > 0 ? 'Đối tác nộp 365home' : ($net < 0 ? '365home chi cho đối tác' : 'Không phát sinh');
    }

    private function build(Worksheet $ws): void
    {
        $cols = count(self::HEADERS);

        foreach (self::WIDTHS as $i => $w) {
            $ws->getColumnDimension(self::col($i + 1))->setWidth($w);
        }

        $withSheet = $this->rows->count();

        $headerRow = $this->titleBlock($ws, $cols, 'BẢNG ĐỐI SOÁT TẤT CẢ ĐỐI TÁC', [
            'Kỳ: ' . $this->start->format('d/m/Y') . ' – ' . $this->end->format('d/m/Y')
                . '  |  ' . $withSheet . ' đối tác',
        ]);

        foreach (self::HEADERS as $i => $label) {
            $ws->setCellValue(self::col($i + 1) . $headerRow, $label);
        }
        $this->headerRow($ws, $headerRow, 1, $cols);

        $row = $headerRow + 1;
        $first = $row;

        foreach ($this->rows as $i => $r) {
            $values = [
                $i + 1,
                $r['name'],
                $r['codes'],
                $r['statuses'],
                $r['orders'], $r['revenue'], $r['commission'], $r['subsidy'], $r['collected'], $r['refunded'],
                abs($r['net']),
                self::direction($r['net'], $r['has_sheet']),
            ];

            foreach ($values as $c => $v) {
                $cell = self::col($c + 1) . $row;
                is_int($v) || is_float($v)
                    ? $ws->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC)
                    : $ws->setCellValueExplicit($cell, (string) ($v ?? ''), DataType::TYPE_STRING);
            }

            $row++;
        }

        $last = $row - 1;

        if ($last >= $first) {
            $this->border($ws, 'A' . $first . ':' . self::col($cols) . $last);
            $ws->getStyle("A{$first}:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("E{$first}:E{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("F{$first}:K{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        }

        $ws->mergeCells("A{$row}:D{$row}");
        $ws->setCellValue("A{$row}", 'TỔNG CỘNG');
        $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (['E', 'F', 'G', 'H', 'I', 'J'] as $c) {
            $ws->setCellValue("{$c}{$row}", $last >= $first ? "=SUM({$c}{$first}:{$c}{$last})" : 0);
            $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($c === 'E' ? '0' : self::MONEY_FORMAT);
        }
        $ws->getStyle("E{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $net = (int) $this->rows->sum('net');
        $ws->setCellValue("K{$row}", abs($net));
        $ws->getStyle("K{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $ws->setCellValue("L{$row}", self::direction($net, $withSheet > 0));
        $this->totalRow($ws, $row, 1, $cols);

        $ws->freezePane('C' . ($headerRow + 1));

        if ($last >= $first) {
            $ws->setAutoFilter('A' . $headerRow . ':' . self::col($cols) . $last);
        }

        $this->printSetup($ws, landscape: true, repeatHeaderRow: $headerRow);
    }
}
