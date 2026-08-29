<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Forms\WarehouseCategoryForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Tables\WarehouseCategoryTable;
use Modules\Warehouse\App\Models\WarehouseCategory;

class WarehouseCategoryResource extends Resource
{
    protected static ?string $model = WarehouseCategory::class;

    protected static ?int $navigationSort = 6;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-tag';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Nhóm vật tư';
    }

    public static function getModelLabel(): string
    {
        return 'Nhóm vật tư';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Nhóm vật tư';
    }

    public static function form(Form $form): Form
    {
        return WarehouseCategoryForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseCategoryTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseCategory::route('/'),
            'create' => Pages\CreateWarehouseCategory::route('/create'),
            'edit'   => Pages\EditWarehouseCategory::route('/{record}/edit'),
        ];
    }
}
