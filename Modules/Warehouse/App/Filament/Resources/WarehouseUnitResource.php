<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Forms\WarehouseUnitForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Tables\WarehouseUnitTable;
use Modules\Warehouse\App\Models\WarehouseUnit;

class WarehouseUnitResource extends Resource
{
    protected static ?string $model = WarehouseUnit::class;

    protected static ?int $navigationSort = 7;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-scale';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý kho';
    }

    public static function getNavigationLabel(): string
    {
        return 'Đơn vị tính';
    }

    public static function getModelLabel(): string
    {
        return 'Đơn vị tính';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Đơn vị tính';
    }

    public static function form(Form $form): Form
    {
        return WarehouseUnitForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseUnitTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseUnit::route('/'),
            'create' => Pages\CreateWarehouseUnit::route('/create'),
            'edit'   => Pages\EditWarehouseUnit::route('/{record}/edit'),
        ];
    }
}
