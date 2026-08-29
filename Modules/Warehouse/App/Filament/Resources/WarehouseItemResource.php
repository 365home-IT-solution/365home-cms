<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Forms\WarehouseItemForm;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Pages;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\RelationManagers\MovementsRelationManager;
use Modules\Warehouse\App\Filament\Resources\WarehouseItemResource\Tables\WarehouseItemTable;
use Modules\Warehouse\App\Models\WarehouseItem;

class WarehouseItemResource extends Resource
{
    protected static ?string $model = WarehouseItem::class;

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cube';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Danh mục vật tư';
    }

    public static function getModelLabel(): string
    {
        return 'Vật tư';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Danh mục vật tư';
    }

    public static function form(Form $form): Form
    {
        return WarehouseItemForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseItemTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseItem::route('/'),
            'create' => Pages\CreateWarehouseItem::route('/create'),
            'edit'   => Pages\EditWarehouseItem::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            MovementsRelationManager::class,
        ];
    }
}
