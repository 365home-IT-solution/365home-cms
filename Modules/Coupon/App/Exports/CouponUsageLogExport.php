<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Promotion\App\Models\CouponUsageLog;

// Xuất lịch sử dùng mã giảm giá theo khoảng ngày chọn trên form của action — CouponUsageLog dùng
// BelongsToPartner nên query tự thừa hưởng scope theo đối tác, cùng nguyên tắc với
// Modules\AuditLog\App\Exports\AuditLogExport.
class CouponUsageLogExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    private const PAYMENT_METHOD_LABELS = [
        'PayOS' => 'Chuyển khoản (PayOS)',
        'cod'   => 'Tiền mặt',
    ];

    private bool $showPartnerColumn;

    public function __construct(private array $filters = [])
    {
        $this->showPartnerColumn = auth()->user()?->isSuperAdmin() ?? false;
    }

    public function collection()
    {
        return CouponUsageLog::query()
            ->with('partner:id,name')
            ->when(! empty($this->filters['date_from']), fn ($q) => $q->where(
                'used_at', '>=', Carbon::parse($this->filters['date_from'])->startOfDay()
            ))
            ->when(! empty($this->filters['date_to']), fn ($q) => $q->where(
                'used_at', '<=', Carbon::parse($this->filters['date_to'])->endOfDay()
            ))
            ->orderBy('used_at', 'desc')
            ->get();
    }

    public function headings(): array
    {
        $headings = [
            'Thời gian dùng', 'Mã', 'Tên mã', 'Đơn hàng', 'Khách hàng', 'SĐT',
            'Số tiền giảm', 'Giá trị đơn', 'Thanh toán', 'Trạng thái',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($record): array
    {
        /** @var CouponUsageLog $record */
        $row = [
            $record->used_at?->format('d/m/Y H:i:s'),
            $record->code,
            $this->sanitize($record->coupon_name),
            $record->order_code,
            $this->sanitize($record->customer_name),
            $record->customer_phone,
            $record->discount_amount,
            $record->order_amount,
            self::PAYMENT_METHOD_LABELS[$record->payment_method] ?? $record->payment_method,
            $record->reversed_at ? 'Đã hoàn' : 'Đã dùng',
        ];

        if ($this->showPartnerColumn) {
            $row[] = $record->partner?->name;
        }

        return $row;
    }

    // Chặn CSV/Excel formula injection — cùng nguyên tắc đã dùng ở
    // Modules\AuditLog\App\Exports\AuditLogExport / Modules\Payment\App\Exports\OrdersExport.
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
                $sheet   = $event->sheet->getDelegate();
                $lastCol = $this->showPartnerColumn ? 'K' : 'J';

                $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '2F5496']],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);

                foreach (range('A', $lastCol) as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            },
        ];
    }
}
