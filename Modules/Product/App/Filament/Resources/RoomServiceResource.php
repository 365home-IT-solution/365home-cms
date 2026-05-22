<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Forms\RoomServiceForm;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Pages;
use Modules\Product\App\Filament\Resources\RoomServiceResource\Tables\RoomServiceTable;
use Modules\Product\App\Models\RoomService;

class RoomServiceResource extends Resource
{
    protected static ?string $model = RoomService::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý API';
    }

    public static function getNavigationLabel(): string
    {
        return 'Dịch vụ Phòng';
    }

    public static function getModelLabel(): string
    {
        return 'Dịch vụ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Dịch vụ Phòng';
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function form(Form $form): Form
    {
        return RoomServiceForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomServiceTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoomService::route('/'),
            'create' => Pages\CreateRoomService::route('/create'),
            'edit'   => Pages\EditRoomService::route('/{record}/edit'),
        ];
    }
}
