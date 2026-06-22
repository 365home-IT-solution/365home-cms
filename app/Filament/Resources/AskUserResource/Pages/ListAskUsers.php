<?php

declare(strict_types=1);

namespace App\Filament\Resources\AskUserResource\Pages;

use App\Filament\Resources\AskUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAskUsers extends ListRecords
{
    protected static string $resource = AskUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Thêm thông báo'),
        ];
    }
}
