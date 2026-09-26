<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseItemResource\Forms\WarehouseItemForm;
use Modules\Minihouse\App\Filament\Resources\WarehouseItemResource\Pages;
use Modules\Minihouse\App\Filament\Resources\WarehouseItemResource\Tables\WarehouseItemTable;
use Modules\Minihouse\App\Models\WarehouseItem;

// Danh mục vật tư tồn kho — mirror Modules\Minihouse\App\Filament\Resources\WarehouseItemResource
// (Home). Không override getEloquentQuery() — WarehouseItem tự lọc theo Toà nhà qua global scope
// 'activeBuilding' (ScopedToActiveBuildingId), CHỈ áp dụng trong đúng panel này
// (ActiveBuildingScope::shouldFilter()), cùng nguyên tắc mọi Resource MiniHouse khác (Room/Contract...).
class WarehouseItemResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseItem::class;

    protected static ?string $navigationIcon  = 'heroicon-o-cube';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Danh mục vật tư';
    protected static ?int    $navigationSort  = 79;

    public static function getModelLabel(): string
    {
        return 'Vật tư';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
    }

    public static function form(Form $form): Form
    {
        return WarehouseItemForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return WarehouseItemTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            WarehouseItemResource\RelationManagers\MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseItems::route('/'),
            'create' => Pages\CreateWarehouseItem::route('/create'),
            'edit'   => Pages\EditWarehouseItem::route('/{record}/edit'),
        ];
    }
}
