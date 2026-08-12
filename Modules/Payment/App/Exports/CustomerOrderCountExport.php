<?php

namespace Modules\Payment\App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Payment\Entities\Order;
use Modules\Category\Entities\Category;
use Carbon\Carbon;

class CustomerOrderCountExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    protected $filters;
    protected $allowedBranchIds;
    protected $rowNumber = 0;
    protected $totalCustomers = 0;

    public function __construct($filters = [], ?array $allowedBranchIds = null)
    {
        $this->filters          = $filters ?? [];
        $this->allowedBranchIds = $allowedBranchIds;
    }

    public function title(): string
    {
        return 'Khách hàng';
    }

    public function collection()
    {
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->whereNotNull('buyer_phone')
            ->where('buyer_phone', '!=', '')
            ->select('id', 'buyer_phone', 'buyer_name', 'created_at', 'status', 'amount');

        if (!empty($this->filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($this->filters['date_from']));
        }
        if (!empty($this->filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($this->filters['date_to']));
        }
        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if ($this->allowedBranchIds !== null) {
            $allowedCategoryIds = Category::with('children')
                ->whereIn('id', $this->allowedBranchIds)
                ->get()
                ->flatMap(fn ($cat) => $cat->children->pluck('id')->push($cat->id))
                ->unique()
                ->values()
                ->toArray();

            $query->whereHas('items.product.categories', function ($q) use ($allowedCategoryIds) {
                $q->whereIn('categories.id', $allowedCategoryIds);
            });
        }

        $orders = $query->orderBy('created_at')->get();

        // Gộp theo số điện thoại: cộng dồn số đơn, gom ngày đặt, lấy tên của đơn gần nhất
        $grouped = [];
        foreach ($orders as $order) {
            $phone = $this->normalizePhone((string) $order->buyer_phone);
            if ($phone === '') {
                continue;
            }

            if (!isset($grouped[$phone])) {
                $grouped[$phone] = [
                    'buyer_phone'   => $phone,
                    'buyer_name'    => '',
                    'dates'         => [],
                    'paid_amounts'  => [],
                    'total_orders'  => 0,
                    'total_revenue' => 0,
                ];
            }

            $name = trim((string) $order->buyer_name);
            if ($name !== '') {
                // $orders được sắp xếp tăng dần theo created_at nên tên của lần lặp cuối
                // (đơn gần nhất) sẽ là giá trị được giữ lại.
                $grouped[$phone]['buyer_name'] = $name;
            }

            $isPaid = $order->status === 'paid';

            $grouped[$phone]['dates'][]        = $order->created_at;
            $grouped[$phone]['paid_amounts'][] = $isPaid ? (float) $order->amount : null;
            $grouped[$phone]['total_orders']++;

            if ($isPaid) {
                $grouped[$phone]['total_revenue'] += (float) $order->amount;
            }
        }

        $this->totalCustomers = count($grouped);

        return collect(array_values($grouped))->sortByDesc('total_orders')->values();
    }

    /**
     * Chuẩn hóa số điện thoại về dạng nội địa 0xxxxxxxxx để gộp đúng khách hàng,
     * vì cùng 1 khách có thể lưu số theo 2 kiểu khác nhau tùy nguồn đặt:
     * - Thành viên: 84898995317 (mã quốc gia, không có số 0 đầu)
     * - Khách vãng lai: 0898995317
     */
    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '84')) {
            $digits = '0' . substr($digits, 2);
        }

        return $digits;
    }

    public function headings(): array
    {
        return [
            'STT',
            'Tên khách hàng',
            'Số điện thoại',
            'Số đơn đã đặt',
            'Thời gian đặt - Số tiền đã thanh toán (từng đơn)',
            'Tổng doanh thu',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $dates       = $row['dates'];
        $paidAmounts = $row['paid_amounts'];

        $orderLines = collect($dates)
            ->map(function ($d, $i) use ($paidAmounts) {
                $date   = $d ? Carbon::parse($d)->format('d/m/Y H:i') : '';
                $amount = $paidAmounts[$i] !== null ? number_format($paidAmounts[$i], 0, ',', '.') . 'đ' : '-';

                return $date . ' - ' . $amount;
            })
            ->implode("\n");

        return [
            $this->rowNumber,
            $row['buyer_name'],
            $row['buyer_phone'],
            $row['total_orders'],
            $orderLines,
            $row['total_revenue'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Chèn dòng tổng số khách hàng lên đầu sheet
                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells('A1:F1');
                $sheet->setCellValue('A1', 'Tổng số khách hàng: ' . $this->totalCustomers);
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                ]);

                $highestRow = $sheet->getHighestRow();

                // Header (đã bị đẩy xuống dòng 2 sau khi chèn dòng tổng)
                $sheet->getStyle('A2:F2')->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '00B050']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
                ]);

                if ($highestRow >= 3) {
                    $sheet->getStyle('D3:D' . $highestRow)->applyFromArray([
                        'font'      => ['bold' => true],
                        'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                    ]);

                    $sheet->getStyle('F3:F' . $highestRow)->applyFromArray([
                        'font'      => ['bold' => true],
                        'alignment' => ['horizontal' => 'right', 'vertical' => 'center'],
                    ]);
                    $sheet->getStyle('F3:F' . $highestRow)->getNumberFormat()->setFormatCode('#,##0"đ"');

                    $sheet->getStyle('E3:E' . $highestRow)
                          ->getAlignment()->setWrapText(true);

                    $sheet->getStyle('A2:F' . $highestRow)->applyFromArray([
                        'borders'   => ['allBorders' => ['borderStyle' => 'thin']],
                        'alignment' => ['vertical' => 'center'],
                    ]);
                }

                foreach (['A', 'B', 'C', 'D', 'F'] as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
                $sheet->getColumnDimension('E')->setWidth(35);
            },
        ];
    }
}
