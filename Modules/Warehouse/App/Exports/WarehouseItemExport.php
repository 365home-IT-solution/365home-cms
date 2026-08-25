<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Warehouse\App\Models\WarehouseItem;

// Xuất đúng danh sách vật tư ĐANG HIỂN THỊ trên bảng (theo bộ lọc/tìm kiếm hiện tại của trang) —
// khác với nút "In danh sách tồn kho" (PDF) vốn luôn in TOÀN BỘ, không phụ thuộc bộ lọc.
class WarehouseItemExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    public function __construct(
        private Builder $query,
        private bool $showPartnerColumn,
    ) {
    }

    public function collection()
    {
        return $this->query
            ->with(['category:id,name', 'unit:id,name', 'branch:id,name', 'partner:id,name'])
            ->orderBy('name')
            ->get();
    }

    public function headings(): array
    {
        $headings = [
            'SKU', 'Tên vật tư', 'Nhóm', 'Đơn vị tính', 'Tồn kho', 'Đang sử dụng', 'Dự phòng',
            'Ngưỡng tối thiểu', 'Đơn giá', 'Đang dùng', 'Ghi chú', 'Chi nhánh',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($item): array
    {
        /** @var WarehouseItem $item */
        $row = [
            $item->sku,
            $item->name,
            $item->category?->name,
            $item->unit?->name,
            $item->quantity,
            $item->quantity_in_use,
            $item->quantity_reserve,
            $item->min_quantity,
            $item->unit_price,
            $item->status ? 'Đang dùng' : 'Ngừng dùng',
            $this->sanitize($item->description),
            $item->branch?->name,
        ];

        if ($this->showPartnerColumn) {
            $row[] = $item->partner?->name;
        }

        return $row;
    }

    // Chặn CSV/Excel formula injection từ nội dung do người dùng nhập (Ghi chú) — cùng nguyên tắc
    // đã dùng ở Modules\Payment\App\Exports\OrdersExport.
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
                $lastCol = $this->showPartnerColumn ? 'M' : 'L';
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
