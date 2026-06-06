<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\Payment\App\Services\CccdScannerService;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('scanCccdQr')
                ->label('[TEST] Quét QR CCCD')
                ->icon('heroicon-m-qr-code')
                ->color('gray')
                ->visible(fn () => (bool) ($this->record->cccd_front || $this->record->cccd_back))
                ->action(function (): void {
                    /** @var \App\Models\Customer $record */
                    $record = $this->record->fresh();
                    $data   = app(CccdScannerService::class)->scanCustomer($record);

                    if (! $data) {
                        Notification::make()
                            ->title('Không đọc được QR CCCD')
                            ->body('Ảnh quá nhỏ hoặc QR bị mờ. Vui lòng upload lại ảnh gốc chất lượng cao (không resize).')
                            ->warning()
                            ->send();
                        return;
                    }

                    $record->update(['cccd_data' => $data]);

                    $this->refreshFormData(['cccd_data']);

                    $note = implode("\n", array_filter([
                        $data['cccd']      ? "Số CCCD:   {$data['cccd']}"      : null,
                        $data['full_name'] ? "Họ và tên: {$data['full_name']}" : null,
                        $data['dob']       ? "Ngày sinh: {$data['dob']}"       : null,
                        $data['gender']    ? "Giới tính: {$data['gender']}"    : null,
                        $data['address']   ? "Địa chỉ:   {$data['address']}"   : null,
                    ]));

                    Notification::make()
                        ->title('Quét CCCD thành công')
                        ->body($note)
                        ->success()
                        ->send();
                }),

            DeleteAction::make()->label('Xoá'),
            RestoreAction::make()->label('Khôi phục'),
            ForceDeleteAction::make()->label('Xoá vĩnh viễn'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
