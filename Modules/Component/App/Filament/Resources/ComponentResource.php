<?php

declare(strict_types=1);

namespace Modules\Component\App\Filament\Resources;

use Modules\Component\App\Filament\Resources\ComponentResource\Forms\ComponentForm;
use Modules\Component\App\Filament\Resources\ComponentResource\Tables\ComponentTable;
use Modules\Component\App\Filament\Resources\ComponentResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Page\Entities\Component as EntitiesComponent;

class ComponentResource extends Resource
{
    protected static ?string $model = EntitiesComponent::class;

    public static function getNavigationIcon(): string
    {
        return __('component::component.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('component::component.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('component::component.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('component::component.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('component::component.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }
    public static function form(Form $form): Form
    {
        return ComponentForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ComponentTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComponent::route('/'),
            'create' => Pages\CreateComponent::route('/create'),
            'edit' => Pages\EditComponent::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }
}
