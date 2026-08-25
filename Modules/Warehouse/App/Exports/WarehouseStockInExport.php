<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Warehouse\App\Models\WarehouseStockIn;

// Xuất đúng danh sách phiếu nhập kho ĐANG HIỂN THỊ trên bảng (theo bộ lọc/tìm kiếm hiện tại).
class WarehouseStockInExport implements FromCollection, WithHeadings, WithMapping, WithEvents
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
            ->orderByDesc('received_at')
            ->get();
    }

    public function headings(): array
    {
        $headings = [
            'Mã phiếu', 'Ngày nhập', 'Số dòng hàng', 'Tổng tiền', 'Người nhập', 'Ghi chú', 'Chi nhánh',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($stockIn): array
    {
        /** @var WarehouseStockIn $stockIn */
        $row = [
            $stockIn->code,
            $stockIn->received_at?->format('d/m/Y H:i'),
            $stockIn->items_count,
            $stockIn->total_amount,
            $stockIn->creator?->fullname ?? $stockIn->creator?->email,
            $this->sanitize($stockIn->note),
            $stockIn->branch?->name,
        ];

        if ($this->showPartnerColumn) {
            $row[] = $stockIn->partner?->name;
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
                $lastCol = $this->showPartnerColumn ? 'H' : 'G';
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
