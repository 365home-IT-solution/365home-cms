<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource\Forms\WarehouseStockReturnForm;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource\Pages;
use Modules\Minihouse\App\Filament\Resources\WarehouseStockReturnResource\Tables\WarehouseStockReturnTable;
use Modules\Minihouse\App\Models\WarehouseStockReturn;

// Phiếu hoàn trả kho — mirror WarehouseStockReturnResource (Home), NHƯNG giữ nút "Tạo" đầy đủ (xem
// giải thích ở WarehouseStockReturnForm) thay vì chỉ tạo qua popup gắn ở Phiếu xuất.
class WarehouseStockReturnResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseStockReturn::class;

    protected static ?string $navigationIcon  = 'heroicon-o-arrow-uturn-left';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phiếu hoàn trả kho';
    protected static ?int    $navigationSort  = 82;

    public static function getModelLabel(): string
    {
        return 'Phiếu hoàn trả kho';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
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
            'index'  => Pages\ListWarehouseStockReturns::route('/'),
            'create' => Pages\CreateWarehouseStockReturn::route('/create'),
            'edit'   => Pages\EditWarehouseStockReturn::route('/{record}/edit'),
        ];
    }
}
