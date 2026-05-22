<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\RoomImageResource;

class CreateRoomImage extends CreateRecord
{
    protected static string $resource = RoomImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->getCancelFormAction(),
            $this->getCreateFormAction(),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['type'] ?? '') === 'gallery') {
            $data['path'] = $data['gallery_path'] ?? [];
        }
        unset($data['gallery_path']);
        return $data;
    }
}
