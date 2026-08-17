<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Forms\WarehouseStockOutForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockOutResource\Tables\WarehouseStockOutTable;
use Modules\Warehouse\App\Models\WarehouseStockOut;

class WarehouseStockOutResource extends Resource
{
    protected static ?string $model = WarehouseStockOut::class;

    protected static ?int $navigationSort = 4;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-up-tray';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý kho';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phiếu xuất kho';
    }

    public static function getModelLabel(): string
    {
        return 'Phiếu xuất kho';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phiếu xuất kho';
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
            'index'  => Pages\ListWarehouseStockOut::route('/'),
            'create' => Pages\CreateWarehouseStockOut::route('/create'),
            'edit'   => Pages\EditWarehouseStockOut::route('/{record}/edit'),
        ];
    }
}
