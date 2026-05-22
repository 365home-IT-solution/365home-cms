<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\Product\App\Filament\Resources\RoomImageResource;

class EditRoomImage extends EditRecord
{
    protected static string $resource = RoomImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getSaveFormAction(),
            DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (($data['type'] ?? '') === 'gallery') {
            $data['gallery_path'] = $data['path'] ?? [];
            $data['path'] = [];
        }
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['type'] ?? '') === 'gallery') {
            $data['path'] = $data['gallery_path'] ?? [];
        }
        unset($data['gallery_path']);
        return $data;
    }
}
