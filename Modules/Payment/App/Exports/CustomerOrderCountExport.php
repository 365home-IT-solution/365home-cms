<?php
namespace Modules\Payment\App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Payment\Entities\Order;
use Carbon\Carbon;

class CustomerOrderCountExport implements WithMultipleSheets
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters ?? [];
    }

    public function sheets(): array
    {
        // Lấy các năm có dữ liệu trong DB
        $query = Order::query()
            ->selectRaw('YEAR(created_at) as year')
            ->whereNotNull('buyer_phone')
            ->where('buyer_phone', '!=', '');

        if (!empty($this->filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($this->filters['date_from']));
        }
        if (!empty($this->filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($this->filters['date_to']));
        }
        if (!empty($this->filters['category_id'])) {
            $query->where('category_id', $this->filters['category_id']);
        }

        $years = $query
            ->groupBy('year')
            ->orderBy('year', 'asc')
            ->pluck('year')
            ->toArray();

        $sheets = [];

        // Sheet từng năm
        foreach ($years as $year) {
            $sheets[] = new CustomerOrderCountSheet($this->filters, (int) $year);
        }

        // Sheet tổng hợp tất cả năm
        $sheets[] = new CustomerOrderCountSheet($this->filters, null);

        return $sheets;
    }
}

class CustomerOrderCountSheet implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    protected $filters;
    protected $year;
    protected $rowNumber = 0;

    public function __construct($filters = [], ?int $year = null)
    {
        $this->filters = $filters ?? [];
        $this->year    = $year;
    }

    public function title(): string
    {
        return $this->year ? 'Năm ' . $this->year : 'Tất cả';
    }

    public function collection()
    {
        $query = Order::query()
            ->selectRaw('
                buyer_phone,
                buyer_name,
                COUNT(*) as total_orders,
                SUM(CASE WHEN status IN ("paid","completed") THEN 1 ELSE 0 END) as paid_orders,
                MIN(created_at) as first_order_at,
                MAX(created_at) as last_order_at
            ')
            ->whereNotNull('buyer_phone')
            ->where('buyer_phone', '!=', '');

        if (!empty($this->filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($this->filters['date_from']));
        }
        if (!empty($this->filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($this->filters['date_to']));
        }
        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }
        if (!empty($this->filters['category_id'])) {
            $query->where('category_id', $this->filters['category_id']);
        }

        // Lọc theo năm nếu có
        if ($this->year) {
            $query->whereYear('created_at', $this->year);
        }

        $rows = $query
            ->groupBy('buyer_phone', 'buyer_name')
            ->orderBy('buyer_phone')
            ->orderByDesc('total_orders')
            ->get();

        // Gộp theo phone
        $grouped = [];
        foreach ($rows as $row) {
            $phone = $row->buyer_phone;

            if (!isset($grouped[$phone])) {
                $grouped[$phone] = [
                    'buyer_phone'    => $phone,
                    'names'          => [],
                    'total_orders'   => 0,
                    'paid_orders'    => 0,
                    'first_order_at' => $row->first_order_at,
                    'last_order_at'  => $row->last_order_at,
                ];
            }

            $name = trim($row->buyer_name ?? '');
            if ($name !== '' && !in_array($name, $grouped[$phone]['names'])) {
                $grouped[$phone]['names'][] = $name;
            }

            $grouped[$phone]['total_orders'] += $row->total_orders;
            $grouped[$phone]['paid_orders']  += $row->paid_orders;

            if ($row->first_order_at < $grouped[$phone]['first_order_at']) {
                $grouped[$phone]['first_order_at'] = $row->first_order_at;
            }
            if ($row->last_order_at > $grouped[$phone]['last_order_at']) {
                $grouped[$phone]['last_order_at'] = $row->last_order_at;
            }
        }

        usort($grouped, fn($a, $b) => $a['first_order_at'] <=> $b['first_order_at']);

        return collect($grouped);
    }

    public function headings(): array
    {
        return [
            'STT',
            'Họ và tên khách hàng',
            'Số điện thoại',
            'Tổng số lần đặt',
            'Số lần thanh toán thành công',
            'Lần đặt đầu tiên',
            'Lần đặt gần nhất',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            implode("\n", $row['names']),
            $row['buyer_phone'],
            $row['total_orders'],
            $row['paid_orders'],
            $row['first_order_at'] ? Carbon::parse($row['first_order_at'])->format('d/m/Y H:i') : '',
            $row['last_order_at']  ? Carbon::parse($row['last_order_at'])->format('d/m/Y H:i')  : '',
        ];
    }

    public function registerEvents(): array
    {
        // Màu tab header theo năm
        $tabColors = [
            2024 => '1565C0',
            2025 => '2E7D32',
            2026 => '6A1B9A',
        ];
        $tabColor = $tabColors[$this->year] ?? '37474F'; // Tổng hợp = xám đậm

        return [
            AfterSheet::class => function (AfterSheet $event) use ($tabColor) {
                $sheet      = $event->sheet->getDelegate();
                $highestRow = $sheet->getHighestRow();

                // Màu tab sheet
                $sheet->getTabColor()->setRGB($tabColor);

                // Header
                $sheet->getStyle('A1:G1')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $tabColor]],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);

                if ($highestRow >= 2) {
                    // Cột D, E căn giữa + bold
                    $sheet->getStyle('D2:E' . $highestRow)->applyFromArray([
                        'font'      => ['bold' => true],
                        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                    ]);

                    // WrapText cột tên
                    $sheet->getStyle('B2:B' . $highestRow)
                          ->getAlignment()
                          ->setWrapText(true);

                    // Highlight + zebra
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $totalOrders = $sheet->getCell('D' . $row)->getValue();
                        if ($totalOrders >= 5) {
                            $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E3F2FD']],
                            ]);
                        } elseif ($row % 2 === 0) {
                            $sheet->getStyle('A' . $row . ':G' . $row)->applyFromArray([
                                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F5F5F5']],
                            ]);
                        }
                    }

                    // Border + căn giữa dọc
                    $sheet->getStyle('A1:G' . $highestRow)->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => 'thin']],
                        'alignment' => ['vertical' => 'center'],
                    ]);
                }

                foreach (range('A', 'G') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
                $sheet->getColumnDimension('B')->setWidth(30);
            },
        ];
    }
}