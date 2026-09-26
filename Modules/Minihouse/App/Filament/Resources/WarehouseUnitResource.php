<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\WarehouseUnitResource\Pages;
use Modules\Minihouse\App\Models\WarehouseUnit;

// Đơn vị tính vật tư — DÙNG CHUNG mọi Toà nhà, mirror WarehouseUnitResource (Home).
class WarehouseUnitResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseUnit::class;

    protected static ?string $navigationIcon  = 'heroicon-o-scale';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Đơn vị tính';
    protected static ?int    $navigationSort  = 85;

    public static function getModelLabel(): string
    {
        return 'Đơn vị tính';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Tên đơn vị')->required()->maxLength(50),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên đơn vị')->searchable()->sortable(),
                TextColumn::make('items_count')->label('Số vật tư')->counts('items'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseUnits::route('/'),
            'create' => Pages\CreateWarehouseUnit::route('/create'),
            'edit'   => Pages\EditWarehouseUnit::route('/{record}/edit'),
        ];
    }
}
