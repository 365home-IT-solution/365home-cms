<?php

declare(strict_types=1);

namespace App\Exports;

use App\Exports\Concerns\StylesSettlementSheet;
use App\Models\Partner;
use App\Models\PartnerSettlement;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Xuất Excel DANH SÁCH các bảng đối soát của 1 đối tác (mỗi bảng 1 dòng) — dùng cho API GET …/settlements/export và nút
 * "Xuất Excel" ở danh sách đối soát trên CMS. Chi tiết từng đơn nằm ở file của từng bảng (PartnerSettlementExport).
 */
class PartnerSettlementListExport implements FromArray, WithEvents, WithTitle
{
    use StylesSettlementSheet;

    private const HEADERS = [
        'STT', 'Mã bảng', 'Từ ngày', 'Đến ngày', 'Trạng thái', 'Số đơn',
        'Doanh thu (đ)', 'Hoa hồng (đ)', '365home bù (đ)', '365home đã thu hộ (đ)', '365home hoàn thay (đ)',
        'Kết quả (đ)', 'Chiều thanh toán', 'Ngày gửi', 'Hạn nộp', 'Ngày thanh toán / chi',
    ];

    private const WIDTHS = [6, 17, 12, 12, 17, 9, 16, 15, 14, 18, 16, 16, 24, 17, 12, 19];

    /** @param Collection<int, PartnerSettlement> $settlements */
    public function __construct(private readonly Partner $partner, private readonly Collection $settlements) {}

    public function title(): string
    {
        return 'Danh sách đối soát';
    }

    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->build($e->sheet->getDelegate())];
    }

    private function build(Worksheet $ws): void
    {
        $cols = count(self::HEADERS);

        foreach (self::WIDTHS as $i => $w) {
            $ws->getColumnDimension(self::col($i + 1))->setWidth($w);
        }

        $from = $this->settlements->min('period_start');
        $to = $this->settlements->max('period_end');

        $headerRow = $this->titleBlock($ws, $cols, 'DANH SÁCH BẢNG ĐỐI SOÁT HOA HỒNG', [
            'Đối tác: ' . ($this->partner->legal_name ?: $this->partner->name)
                . ($this->settlements->isEmpty()
                    ? '  |  Chưa có bảng đối soát nào'
                    : '  |  Từ ' . $from->format('d/m/Y') . ' đến ' . $to->format('d/m/Y') . '  |  ' . $this->settlements->count() . ' bảng'),
        ]);

        foreach (self::HEADERS as $i => $label) {
            $ws->setCellValue(self::col($i + 1) . $headerRow, $label);
        }
        $this->headerRow($ws, $headerRow, 1, $cols);

        $row = $headerRow + 1;
        $first = $row;

        foreach ($this->settlements as $i => $s) {
            $direction = match ($s->direction()) {
                'partner_pays'  => 'Đối tác nộp 365home',
                'platform_pays' => '365home chi cho đối tác',
                default         => 'Không phát sinh',
            };
            $paidAt = $s->paid_at ?? $s->deducted_at ?? $s->paid_out_at;

            $values = [
                $i + 1,
                $s->code,
                $s->period_start->format('d/m/Y'),
                $s->period_end->format('d/m/Y'),
                PartnerSettlement::STATUSES[$s->status] ?? $s->status,
                (int) $s->orders_count,
                (int) $s->revenue_total,
                (int) $s->commission_total,
                (int) $s->subsidy_total,
                (int) $s->platform_collected_total,
                (int) $s->platform_refunded_total,
                abs((int) $s->net_amount),
                $direction,
                $s->sent_at?->format('d/m/Y H:i') ?? '',
                $s->due_at?->format('d/m/Y') ?? '',
                $paidAt?->format('d/m/Y H:i') ?? '',
            ];

            foreach ($values as $c => $v) {
                $cell = self::col($c + 1) . $row;
                is_int($v) || is_float($v)
                    ? $ws->setCellValueExplicit($cell, $v, DataType::TYPE_NUMERIC)
                    : $ws->setCellValueExplicit($cell, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }

        $last = $row - 1;

        if ($last >= $first) {
            $body = 'A' . $first . ':' . self::col($cols) . $last;
            $this->border($ws, $body);
            $ws->getStyle($body)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $ws->getStyle("A{$first}:A{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("C{$first}:D{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("F{$first}:F{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("N{$first}:P{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle("G{$first}:L{$last}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);

            for ($r = $first; $r <= $last; $r++) {
                if (($r - $first) % 2 === 1) {
                    $this->soft($ws, "A{$r}:" . self::col($cols) . $r);
                }
            }
        }

        $ws->mergeCells("A{$row}:E{$row}");
        $ws->setCellValue("A{$row}", 'TỔNG CỘNG');
        $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (['F', 'G', 'H', 'I', 'J', 'K'] as $c) {
            $ws->setCellValue("{$c}{$row}", $last >= $first ? "=SUM({$c}{$first}:{$c}{$last})" : 0);
            $ws->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode($c === 'F' ? '0' : self::MONEY_FORMAT);
        }
        $ws->getStyle("F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Kết quả tổng: cùng cách thể hiện với từng dòng (số tuyệt đối + chiều thanh toán).
        $net = (int) $this->settlements->sum('net_amount');
        $ws->setCellValue("L{$row}", abs($net));
        $ws->getStyle("L{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $ws->setCellValue("M{$row}", $net > 0 ? 'Đối tác nộp 365home' : ($net < 0 ? '365home chi cho đối tác' : 'Không phát sinh'));
        $this->totalRow($ws, $row, 1, $cols);

        $ws->freezePane('C' . ($headerRow + 1));

        if ($last >= $first) {
            $ws->setAutoFilter('A' . $headerRow . ':' . self::col($cols) . $last);
        }

        $this->printSetup($ws, landscape: true, repeatHeaderRow: $headerRow);
    }
}
