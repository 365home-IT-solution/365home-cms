<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Forms\RoomSpecialForm;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Pages;
use Modules\Product\App\Filament\Resources\RoomSpecialResource\Tables\RoomSpecialTable;
use Modules\Product\App\Models\RoomSpecial;

class RoomSpecialResource extends Resource
{
    protected static ?string $model = RoomSpecial::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-star';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Điểm Đặc Biệt';
    }

    public static function getModelLabel(): string
    {
        return 'Điểm đặc biệt';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Điểm Đặc Biệt';
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
        return RoomSpecialForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomSpecialTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomSpecial::route('/'),
            'create' => Pages\CreateRoomSpecial::route('/create'),
            'edit'   => Pages\EditRoomSpecial::route('/{record}/edit'),
        ];
    }
}
