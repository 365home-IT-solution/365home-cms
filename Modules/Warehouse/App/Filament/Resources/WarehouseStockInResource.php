<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Forms\WarehouseStockInForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockInResource\Tables\WarehouseStockInTable;
use Modules\Warehouse\App\Models\WarehouseStockIn;

class WarehouseStockInResource extends Resource
{
    protected static ?string $model = WarehouseStockIn::class;

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-down-tray';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý kho';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phiếu nhập kho';
    }

    public static function getModelLabel(): string
    {
        return 'Phiếu nhập kho';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phiếu nhập kho';
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
            'index'  => Pages\ListWarehouseStockIn::route('/'),
            'create' => Pages\CreateWarehouseStockIn::route('/create'),
            'edit'   => Pages\EditWarehouseStockIn::route('/{record}/edit'),
        ];
    }
}
