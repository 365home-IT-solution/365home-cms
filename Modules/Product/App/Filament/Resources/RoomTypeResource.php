<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomTypeResource\Forms\RoomTypeForm;
use Modules\Product\App\Filament\Resources\RoomTypeResource\Pages;
use Modules\Product\App\Filament\Resources\RoomTypeResource\Tables\RoomTypeTable;
use Modules\Product\App\Models\RoomType;

class RoomTypeResource extends Resource
{
    protected static ?string $model = RoomType::class;

    public static function getNavigationIcon(): string
    {
        return __('product::room_type.resource.navigation_icon');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('product::room_type.resource.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('product::room_type.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('product::room_type.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('product::room_type.resource.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return RoomTypeForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomTypeTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomType::route('/'),
            'create' => Pages\CreateRoomType::route('/create'),
            'edit'   => Pages\EditRoomType::route('/{record}/edit'),
        ];
    }
}
