<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\BuildingResource\Forms\BuildingForm;
use Modules\Minihouse\App\Filament\Resources\BuildingResource\Pages;
use Modules\Minihouse\App\Filament\Resources\BuildingResource\Tables\BuildingTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\Building;

class BuildingResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Building::class;
    protected static ?string $navigationIcon  = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Toà nhà';
    protected static ?int $navigationSort     = 1;

    public static function getModelLabel(): string
    {
        return 'Toà nhà';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Toà nhà';
    }

    public static function permissionGroup(): string
    {
        return 'buildings';
    }

    public static function form(Form $form): Form
    {
        return BuildingForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return BuildingTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBuildings::route('/'),
            'create' => Pages\CreateBuilding::route('/create'),
            'edit'   => Pages\EditBuilding::route('/{record}/edit'),
        ];
    }
}
