<?php

namespace Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\PanoramaSceneResource;

class EditPanoramaScene extends EditRecord
{
    protected static string $resource = PanoramaSceneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('Xem thử')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn () => route('minihouse.tour.scene', ['building' => $this->record->building_id, 'scene' => $this->record->id]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }
}
