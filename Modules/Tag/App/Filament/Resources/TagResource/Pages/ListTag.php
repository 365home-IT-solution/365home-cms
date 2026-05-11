<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament\Resources\TagResource\Pages;

use Modules\Tag\App\Filament\Resources\TagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTag extends ListRecords
{
    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
