<?php

declare(strict_types=1);

namespace Modules\AuditLog\App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\AuditLog\Entities\AuditLog;

// Xuất lịch sử thao tác theo khoảng ngày người dùng chọn trên form của action (không phụ thuộc bộ
// lọc đang áp trên bảng) — AuditLog::query() tự thừa hưởng global scope BelongsToPartner nên đối
// tác/nhân viên chỉ xuất được đúng dữ liệu của mình, super_admin xuất được toàn bộ kèm cột "Đối tác".
class AuditLogExport implements FromCollection, WithHeadings, WithMapping, WithEvents
{
    private bool $showPartnerColumn;

    public function __construct(private array $filters = [])
    {
        $this->showPartnerColumn = auth()->user()?->isSuperAdmin() ?? false;
    }

    public function collection()
    {
        return AuditLog::query()
            ->with('partner:id,name')
            ->when(! empty($this->filters['date_from']), fn ($q) => $q->where(
                'created_at', '>=', Carbon::parse($this->filters['date_from'])->startOfDay()
            ))
            ->when(! empty($this->filters['date_to']), fn ($q) => $q->where(
                'created_at', '<=', Carbon::parse($this->filters['date_to'])->endOfDay()
            ))
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function headings(): array
    {
        $headings = [
            'Thời gian', 'Người thực hiện', 'Email', 'Vai trò', 'Thao tác',
            'Đối tượng', 'Chi tiết đối tượng', 'IP',
        ];

        if ($this->showPartnerColumn) {
            $headings[] = 'Đối tác';
        }

        return $headings;
    }

    public function map($record): array
    {
        /** @var AuditLog $record */
        $row = [
            $record->created_at?->format('d/m/Y H:i:s'),
            $this->sanitize($record->user_name),
            $record->user_email,
            $record->performer_role,
            AuditLog::actionLabels()[$record->action] ?? $record->action,
            AuditLog::moduleLabels()[$record->module] ?? $record->module,
            $this->sanitize($record->target_label),
            $record->ip_address,
        ];

        if ($this->showPartnerColumn) {
            $row[] = $record->partner?->name;
        }

        return $row;
    }

    // Chặn CSV/Excel formula injection từ dữ liệu người dùng có thể ảnh hưởng (tên, nhãn đối tượng) —
    // cùng nguyên tắc đã dùng ở Modules\Payment\App\Exports\OrdersExport / WarehouseItemExport.
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
                $lastCol = $this->showPartnerColumn ? 'I' : 'H';

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
