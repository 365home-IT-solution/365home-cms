<?php

declare(strict_types=1);

namespace Modules\Menu\App\Filament\Resources\MenuResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;
use Modules\Menu\Concerns\HasLocationAction;

class ListMenus extends ListRecords
{
    use HasLocationAction;

    public static function getResource(): string
    {
        return FilamentMenuBuilderPlugin::get()->getResource();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
