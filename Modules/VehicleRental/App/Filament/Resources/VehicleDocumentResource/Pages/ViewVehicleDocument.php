<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource\Pages;

use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Modules\VehicleRental\App\Filament\Resources\VehicleDocumentResource;
use Modules\VehicleRental\App\Models\VehicleDocument;
use Modules\VehicleRental\App\Services\VehicleKycService;

class ViewVehicleDocument extends ViewRecord
{
    protected static string $resource = VehicleDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $this->record->status !== VehicleDocument::STATUS_APPROVED)
                ->requiresConfirmation()
                ->modalDescription('Xác nhận ảnh CCCD, GPLX và chân dung khớp nhau, GPLX đúng hạng và còn hạn.')
                ->action(function () {
                    try {
                        app(VehicleKycService::class)->approve($this->record, (string) auth()->id());
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()->success()->title('Đã duyệt giấy tờ')->send();
                }),

            Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => $this->record->status !== VehicleDocument::STATUS_REJECTED)
                ->form([Textarea::make('reason')->label('Lý do (khách sẽ thấy)')->required()->rows(2)->maxLength(255)])
                ->action(function (array $data) {
                    app(VehicleKycService::class)->reject($this->record, $data['reason'], (string) auth()->id());
                    $this->record->refresh();
                    Notification::make()->success()->title('Đã từ chối hồ sơ')->send();
                }),
        ];
    }
}
