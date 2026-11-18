<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleResource\Pages;

use DomainException;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Services\VehicleGpsLinker;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    // Lưu xe xong thì áp dụng lựa chọn thiết bị GPS (chọn khác = đổi thiết bị; xoá chọn = bỏ gắn).
    protected function afterSave(): void
    {
        $linker = app(VehicleGpsLinker::class);
        if (! $linker->available()) {
            return;
        }
        try {
            $linker->link($this->record, ($this->data['motrak_device_id'] ?? null) ? (int) $this->data['motrak_device_id'] : null);
        } catch (DomainException $e) {
            Notification::make()->warning()->title('Chưa đổi được thiết bị GPS')->body($e->getMessage())->persistent()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('toggleRental')
                ->label(fn () => $this->record->isRentable() ? 'Ngừng cho thuê' : 'Mở cho thuê')
                ->icon(fn () => $this->record->isRentable() ? 'heroicon-o-pause-circle' : 'heroicon-o-check-circle')
                ->color(fn () => $this->record->isRentable() ? 'gray' : 'success')
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->isRentable()
                    ? 'Xe sẽ không còn hiện cho khách đặt mới (các đơn đã đặt vẫn được giữ).'
                    : 'Xe sẽ hiện lại cho khách tìm và đặt.')
                ->action(function () {
                    $this->record->setRentable(! $this->record->isRentable());
                    $this->record->refresh();
                    $this->refreshFormData(['status']);
                    Notification::make()->success()->title($this->record->isRentable() ? 'Đã mở cho thuê' : 'Đã ngừng cho thuê')->send();
                }),
            Actions\DeleteAction::make()
                // Xe còn đơn đang giữ thì không cho xoá (đơn tham chiếu xe); chuyển sang "Ngừng cho thuê" thay vì xoá.
                ->before(function (Actions\DeleteAction $action) {
                    if ($this->record->bookings()->whereIn('status', VehicleBooking::BLOCKING_STATUSES)->exists()) {
                        Notification::make()->danger()->title('Xe còn đơn thuê đang giữ')
                            ->body('Hoàn tất hoặc huỷ các đơn đó, hoặc đổi trạng thái xe sang "Ngừng cho thuê".')->send();
                        $action->cancel();
                    }
                }),
        ];
    }
}
