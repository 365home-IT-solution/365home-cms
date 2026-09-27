<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource\Forms\WarehouseStockCheckForm;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource\Pages;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockCheckResource\Tables\WarehouseStockCheckTable;
use Modules\Minihouse\App\Models\WarehouseStockCheck;

// Phiếu kiểm kê — mirror WarehouseStockCheckResource (Home, bỏ tính năng bàn giao ca).
class WarehouseStockCheckResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseStockCheck::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phiếu kiểm kê';
    protected static ?int    $navigationSort  = 83;

    public static function getModelLabel(): string
    {
        return 'Phiếu kiểm kê';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
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
            'index'  => Pages\ListWarehouseStockChecks::route('/'),
            'create' => Pages\CreateWarehouseStockCheck::route('/create'),
            'edit'   => Pages\EditWarehouseStockCheck::route('/{record}/edit'),
        ];
    }
}
