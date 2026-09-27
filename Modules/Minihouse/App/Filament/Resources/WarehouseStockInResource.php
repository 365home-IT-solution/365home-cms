<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource\Forms\WarehouseStockInForm;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource\Pages;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockInResource\Tables\WarehouseStockInTable;
use Modules\Minihouse\App\Models\WarehouseStockIn;

// Phiếu nhập kho — mirror WarehouseStockInResource (Home).
class WarehouseStockInResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseStockIn::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-down-tray';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phiếu nhập kho';
    protected static ?int    $navigationSort  = 80;

    public static function getModelLabel(): string
    {
        return 'Phiếu nhập kho';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
    }

    public static function form(Form $form): Form
    {
        return WarehouseStockInForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseStockInTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseStockIns::route('/'),
            'create' => Pages\CreateWarehouseStockIn::route('/create'),
            'edit'   => Pages\EditWarehouseStockIn::route('/{record}/edit'),
        ];
    }
}
