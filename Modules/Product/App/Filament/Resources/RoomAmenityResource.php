<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomAmenityResource\Forms\RoomAmenityForm;
use Modules\Product\App\Filament\Resources\RoomAmenityResource\Pages;
use Modules\Product\App\Filament\Resources\RoomAmenityResource\Tables\RoomAmenityTable;
use Modules\Product\App\Models\RoomAmenity;

class RoomAmenityResource extends Resource
{
    protected static ?string $model = RoomAmenity::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-sparkles';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Tiện ích Phòng';
    }

    public static function getModelLabel(): string
    {
        return 'Tiện ích';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tiện ích Phòng';
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
        return RoomAmenityForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomAmenityTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomAmenity::route('/'),
            'create' => Pages\CreateRoomAmenity::route('/create'),
            'edit'   => Pages\EditRoomAmenity::route('/{record}/edit'),
        ];
    }
}
