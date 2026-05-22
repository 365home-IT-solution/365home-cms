<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomImageResource\Forms\RoomImageForm;
use Modules\Product\App\Filament\Resources\RoomImageResource\Pages;
use Modules\Product\App\Filament\Resources\RoomImageResource\Tables\RoomImageTable;
use Modules\Product\App\Models\RoomImage;

class RoomImageResource extends Resource
{
    protected static ?string $model = RoomImage::class;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-photo';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Ảnh Phòng';
    }

    public static function getModelLabel(): string
    {
        return 'Ảnh phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Ảnh Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return RoomImageForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomImageTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomImage::route('/'),
            'create' => Pages\CreateRoomImage::route('/create'),
            'edit'   => Pages\EditRoomImage::route('/{record}/edit'),
        ];
    }
}
