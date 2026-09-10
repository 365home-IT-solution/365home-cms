<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\InvoiceResource;
use Modules\Minihouse\App\Models\Invoice;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    // Trước đây chỉ dựa vào ràng buộc unique(contract_id, month) ở DB để chặn tạo trùng — ràng buộc
    // đó đã bị BỎ (xem migration 2026_09_10_140000) vì nó không biết gì về xoá mềm, khiến xoá 1 hoá
    // đơn xong là KHÔNG BAO GIỜ tạo lại được cho đúng hợp đồng + tháng đó nữa. Bỏ constraint DB thì
    // phải tự kiểm tra trùng Ở ĐÂY thay thế, nếu không nhân viên có thể vô tình tạo 2 hoá đơn active
    // cho cùng 1 hợp đồng + tháng qua form tạo tay.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['contract_id'] ?? null) || blank($data['month'] ?? null)) {
            return $data;
        }

        $month = \Illuminate\Support\Carbon::parse($data['month']);

        $exists = Invoice::query()
            ->where('contract_id', $data['contract_id'])
            ->whereYear('month', $month->year)
            ->whereMonth('month', $month->month)
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Hợp đồng này đã có hoá đơn cho tháng ' . $month->format('m/Y'))
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }
}
