<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Pages;

use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\ContractResource;
use Modules\Minihouse\App\Filament\Resources\TenantResource;
use Modules\Minihouse\App\Observers\TenantObserver;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Chỉ hiện khi khách ĐANG có phòng (room_id tự đồng bộ theo hợp đồng "Đang hiệu lực" —
            // xem ContractObserver::syncTenant()) — bấm để mở đúng hợp đồng đó, tránh phải tự tìm ở
            // danh sách Hợp đồng. Dùng Tenant::activeContract() thay vì đoán qua room_id vì 1 phòng
            // có thể có NHIỀU hợp đồng lịch sử (đã hết hạn) — phải lọc đúng status "active".
            Actions\Action::make('goToContract')
                ->label('Đi đến hợp đồng')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn () => (bool) $this->record->activeContract())
                ->url(fn () => ContractResource::getUrl('edit', ['record' => $this->record->activeContract()])),
            // Bấm tay khi cần quét lại (ảnh mờ lần đầu quét hỏng, hoặc muốn xác nhận lại) — giống
            // hệt nút "Quét CCCD" bên Home (App\Filament\Resources\CustomerResource\Pages\
            // EditCustomer). Bấm tay = chủ động yêu cầu quét lại nên GHI ĐÈ luôn theo ảnh hiện có,
            // không giữ quy tắc "chỉ điền field trống" (quy tắc đó chỉ áp dụng cho lần tự động khi
            // lưu — xem TenantObserver::saved()).
            Actions\Action::make('scanCccd')
                ->label('Quét CCCD')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->visible(fn () => (bool) ($this->record->id_card_front || $this->record->id_card_back))
                ->action(function (): void {
                    $record  = $this->record->fresh();
                    $updated = app(TenantObserver::class)->scanAndFill($record, overwrite: true);

                    if (empty($updated)) {
                        Notification::make()
                            ->title('Không đọc được thông tin từ ảnh CCCD')
                            ->body('Ảnh quá mờ/nhỏ hoặc không phải CCCD. Vui lòng tải lại ảnh gốc chất lượng cao.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $this->refreshFormData(array_keys($updated));

                    $labels = [
                        'fullname'          => 'Họ tên',
                        'id_card_number'    => 'Số CCCD',
                        'date_of_birth'     => 'Ngày sinh',
                        'gender'            => 'Giới tính',
                        'permanent_address' => 'Nơi thường trú',
                    ];

                    $note = collect($updated)
                        ->map(fn ($value, $field) => ($labels[$field] ?? $field) . ': ' . ($value instanceof \Carbon\Carbon ? $value->format('d/m/Y') : $value))
                        ->implode("\n");

                    Notification::make()
                        ->title('Quét CCCD thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
