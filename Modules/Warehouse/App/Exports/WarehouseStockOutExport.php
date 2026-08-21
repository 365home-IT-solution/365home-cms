<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Warehouse\App\Models\WarehouseStockOut;

// Xuất đúng danh sách phiếu xuất kho ĐANG HIỂN THỊ trên bảng (theo bộ lọc/tìm kiếm hiện tại).
class WarehouseStockOutExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    public function __construct(
        private Builder $query,
        private bool $showPartnerColumn,
    ) {
    }

    public function collection()
    {
        return $this->query
            ->with(['room:id,name', 'employee:id,name', 'creator:id,fullname,email', 'branch:id,name', 'partner:id,name'])
            ->withCount('items')
            ->orderByDesc('issued_at')
            ->get()
            ->each(fn (WarehouseStockOut $stockOut) => $stockOut->setAttribute('reasons_summary', $stockOut->reasonsSummary()));
    }

    public function headings(): array
    {
        $headings = [
            'Mã phiếu', 'Ngày xuất', 'Lý do', 'Người xuất', 'Phòng nhận', 'Bộ phận/nơi nhận',
            'Số dòng hàng', 'Ghi chú', 'Chi nhánh',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($stockOut): array
    {
        /** @var WarehouseStockOut $stockOut */
        $row = [
            $stockOut->code,
            $stockOut->issued_at?->format('d/m/Y H:i'),
            $stockOut->reasons_summary,
            $stockOut->creator?->fullname ?? $stockOut->creator?->email,
            $stockOut->room?->name,
            $this->sanitize($stockOut->issued_to),
            $stockOut->items_count,
            $this->sanitize($stockOut->note),
            $stockOut->branch?->name,
        ];

        if ($this->showPartnerColumn) {
            $row[] = $stockOut->partner?->name;
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
                $lastCol = $this->showPartnerColumn ? 'J' : 'I';
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
