<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Forms\WarehouseStockOutForm;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Pages;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockOutResource\Tables\WarehouseStockOutTable;
use Modules\Minihouse\App\Models\WarehouseStockOut;

// Phiếu xuất kho — mirror WarehouseStockOutResource (Home).
class WarehouseStockOutResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseStockOut::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phiếu xuất kho';
    protected static ?int    $navigationSort  = 81;

    public static function getModelLabel(): string
    {
        return 'Phiếu xuất kho';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
    }

    public static function form(Form $form): Form
    {
        return WarehouseStockOutForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseStockOutTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseStockOuts::route('/'),
            'create' => Pages\CreateWarehouseStockOut::route('/create'),
            'edit'   => Pages\EditWarehouseStockOut::route('/{record}/edit'),
        ];
    }
}
