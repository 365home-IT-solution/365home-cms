<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Forms\WarehouseStockReturnForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Tables\WarehouseStockReturnTable;
use Modules\Warehouse\App\Models\WarehouseStockReturn;

class WarehouseStockReturnResource extends Resource
{
    protected static ?string $model = WarehouseStockReturn::class;

    protected static ?int $navigationSort = 5;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phiếu hoàn trả kho';
    }

    public static function getModelLabel(): string
    {
        return 'Phiếu hoàn trả kho';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phiếu hoàn trả kho';
    }

    public static function form(Form $form): Form
    {
        return WarehouseStockReturnForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseStockReturnTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseStockReturn::route('/'),
            'create' => Pages\CreateWarehouseStockReturn::route('/create'),
            'edit'   => Pages\EditWarehouseStockReturn::route('/{record}/edit'),
        ];
    }
}
