<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Warehouse\App\Models\WarehouseStockCheck;

// Xuất đúng danh sách phiếu kiểm kê ĐANG HIỂN THỊ trên bảng (theo bộ lọc/tìm kiếm hiện tại).
class WarehouseStockCheckExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    public function __construct(
        private Builder $query,
        private bool $showPartnerColumn,
    ) {
    }

    public function collection()
    {
        return $this->query
            ->with(['creator:id,fullname,email', 'branch:id,name', 'partner:id,name'])
            ->withCount('items')
            ->withSum('items', 'difference')
            ->orderByDesc('checked_at')
            ->get();
    }

    public function headings(): array
    {
        $headings = [
            'Mã phiếu', 'Ngày kiểm kê', 'Số dòng', 'Tổng chênh lệch', 'Người kiểm kê',
            'Trạng thái bàn giao', 'Ghi chú', 'Chi nhánh',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($stockCheck): array
    {
        /** @var WarehouseStockCheck $stockCheck */
        $row = [
            $stockCheck->code,
            $stockCheck->checked_at?->format('d/m/Y H:i'),
            $stockCheck->items_count,
            $stockCheck->items_sum_difference,
            $stockCheck->creator?->fullname ?? $stockCheck->creator?->email,
            WarehouseStockCheck::HANDOVER_LABELS[$stockCheck->handover_status] ?? 'Chưa bàn giao',
            $this->sanitize($stockCheck->note),
            $stockCheck->branch?->name,
        ];

        if ($this->showPartnerColumn) {
            $row[] = $stockCheck->partner?->name;
        }

        return $row;
    }

    private function sanitize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastCol = $this->showPartnerColumn ? 'I' : 'H';
                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '2F5496']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                foreach (range('A', $lastCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
