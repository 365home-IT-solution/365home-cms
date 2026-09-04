<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\RoomResource\Forms\RoomForm;
use Modules\Minihouse\App\Filament\Resources\RoomResource\Pages;
use Modules\Minihouse\App\Filament\Resources\RoomResource\Tables\RoomTable;
use Modules\Minihouse\App\Models\Room;

class RoomResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Room::class;
    protected static ?string $navigationIcon  = 'heroicon-o-home-modern';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phòng';
    protected static ?int $navigationSort     = 2;

    public static function getModelLabel(): string
    {
        return 'Phòng';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phòng';
    }

    public static function permissionGroup(): string
    {
        return 'rooms';
    }

    public static function form(Form $form): Form
    {
        return RoomForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return RoomTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRooms::route('/'),
            'create' => Pages\CreateRoom::route('/create'),
            'edit'   => Pages\EditRoom::route('/{record}/edit'),
        ];
    }
}
