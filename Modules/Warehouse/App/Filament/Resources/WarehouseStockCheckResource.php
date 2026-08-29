<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Forms\WarehouseStockCheckForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockCheckResource\Tables\WarehouseStockCheckTable;
use Modules\Warehouse\App\Models\WarehouseStockCheck;

class WarehouseStockCheckResource extends Resource
{
    protected static ?string $model = WarehouseStockCheck::class;

    protected static ?int $navigationSort = 5;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-clipboard-document-check';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phiếu kiểm kê';
    }

    public static function getModelLabel(): string
    {
        return 'Phiếu kiểm kê';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Phiếu kiểm kê';
    }

    public static function form(Form $form): Form
    {
        return WarehouseStockCheckForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseStockCheckTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseStockCheck::route('/'),
            'create' => Pages\CreateWarehouseStockCheck::route('/create'),
            'edit'   => Pages\EditWarehouseStockCheck::route('/{record}/edit'),
        ];
    }
}
