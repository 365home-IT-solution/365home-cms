<?php

namespace Modules\Promotion\App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Promotion\App\Models\CouponUsage;

class CustomerVoucherUsageExport implements FromCollection, WithHeadings, WithMapping, WithEvents, WithTitle
{
    protected $filters;
    protected $rowNumber = 0;
    protected $totalCustomers = 0;

    public function __construct($filters = [])
    {
        $this->filters = $filters ?? [];
    }

    public function title(): string
    {
        return 'Khách hàng dùng voucher';
    }

    public function collection()
    {
        $query = CouponUsage::with(['customer', 'coupon'])
            ->whereNotNull('customer_id');

        if (!empty($this->filters['date_from'])) {
            $query->where('used_at', '>=', Carbon::parse($this->filters['date_from'])->startOfDay());
        }
        if (!empty($this->filters['date_to'])) {
            $query->where('used_at', '<=', Carbon::parse($this->filters['date_to'])->endOfDay());
        }

        $usages = $query->orderBy('used_at')->get();

        // Gộp theo khách hàng: cộng dồn số lượt dùng, gom chi tiết từng voucher, cộng dồn số tiền đã giảm.
        $grouped = [];
        foreach ($usages as $usage) {
            $customerId = $usage->customer_id;

            if (!isset($grouped[$customerId])) {
                $grouped[$customerId] = [
                    'customer_name'    => $usage->customer->fullname ?? '',
                    'customer_phone'   => $usage->customer->phone ?? '',
                    'usage_lines'      => [],
                    'total_usages'     => 0,
                    'total_discount'   => 0,
                ];
            }

            $grouped[$customerId]['usage_lines'][]  = [
                'code'            => $usage->code,
                'used_at'         => $usage->used_at,
                'discount_amount' => $usage->discount_amount,
            ];
            $grouped[$customerId]['total_usages']++;
            $grouped[$customerId]['total_discount'] += (int) ($usage->discount_amount ?? 0);
        }

        $this->totalCustomers = count($grouped);

        return collect(array_values($grouped))->sortByDesc('total_usages')->values();
    }

    public function headings(): array
    {
        return [
            'STT',
            'Tên khách hàng',
            'Số điện thoại',
            'Số lượt dùng voucher',
            'Chi tiết voucher đã dùng',
            'Tổng tiền đã giảm',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $lines = collect($row['usage_lines'])
            ->map(function ($line) {
                $date     = $line['used_at'] ? Carbon::parse($line['used_at'])->format('d/m/Y H:i') : '';
                $discount = $line['discount_amount'] !== null
                    ? number_format((float) $line['discount_amount'], 0, ',', '.') . 'đ'
                    : 'Không rõ';

                return $line['code'] . ' - ' . $date . ' - ' . $discount;
            })
            ->implode("\n");

        return [
            $this->rowNumber,
            $row['customer_name'],
            $row['customer_phone'],
            $row['total_usages'],
            $lines,
            $row['total_discount'],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->insertNewRowBefore(1, 1);
                $sheet->mergeCells('A1:F1');
                $sheet->setCellValue('A1', 'Tổng số khách hàng: ' . $this->totalCustomers);
                $sheet->getStyle('A1')->applyFromArray([
                    'font'      => ['bold' => true, 'size' => 13],
                    'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                ]);

                $highestRow = $sheet->getHighestRow();

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
