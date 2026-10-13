<?php

declare(strict_types=1);

namespace App\Filament\Resources\MiniGameResource\Pages;

use App\Filament\Resources\MiniGameResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMiniGame extends EditRecord
{
    protected static string $resource = MiniGameResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Xoá'),
        ];
    }
}
