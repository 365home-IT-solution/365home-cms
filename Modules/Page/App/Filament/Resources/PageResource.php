<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources;

use Modules\Page\App\Filament\Resources\PageResource\Forms\PageForm;
use Modules\Page\App\Filament\Resources\PageResource\Tables\PageTable;
use Modules\Page\Entities\Page;
use Modules\Page\App\Filament\Resources\PageResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;
    public static function getNavigationIcon(): string
    {
        return __('page::page.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('page::page.resource.navigation_group');
    }
    public static function getNavigationLabel(): string
    {
        return __('page::page.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('page::page.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('page::page.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return PageForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return PageTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPage::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
