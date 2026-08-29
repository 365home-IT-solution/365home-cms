<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament\Resources\PostCategoryResource\Pages;

use Modules\Category\App\Filament\Resources\PostCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;

class ListPostCategory extends ListRecords
{
    protected static string $resource = PostCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->modalWidth(MaxWidth::Full),
        ];
    }
}
