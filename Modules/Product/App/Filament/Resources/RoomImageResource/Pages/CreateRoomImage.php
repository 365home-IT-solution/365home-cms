<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomImageResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\RoomImageResource;

class CreateRoomImage extends CreateRecord
{
    protected static string $resource = RoomImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Quay lại')
                ->url(static::getResource()::getUrl('index'))
                ->color('gray'),
            Action::make('create')
                ->label('Tạo')
                ->action(fn () => $this->create())
                ->color('primary'),
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
