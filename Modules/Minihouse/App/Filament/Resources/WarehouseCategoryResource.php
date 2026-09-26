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
use Modules\Minihouse\App\Filament\Resources\WarehouseCategoryResource\Pages;
use Modules\Minihouse\App\Models\WarehouseCategory;

// Nhóm vật tư — DÙNG CHUNG mọi Toà nhà (không lọc theo building_id), mirror
// Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource (Home), bỏ Select "đối tác" vì
// MiniHouse không có tầng đó.
class WarehouseCategoryResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = WarehouseCategory::class;

    protected static ?string $navigationIcon  = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Nhóm vật tư';
    protected static ?int    $navigationSort  = 84;

    public static function getModelLabel(): string
    {
        return 'Nhóm vật tư';
    }

    public static function permissionGroup(): string
    {
        return 'warehouse';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')->label('Tên nhóm')->required()->maxLength(150),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên nhóm')->searchable()->sortable(),
                TextColumn::make('items_count')->label('Số vật tư')->counts('items'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->bulkActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWarehouseCategories::route('/'),
            'create' => Pages\CreateWarehouseCategory::route('/create'),
            'edit'   => Pages\EditWarehouseCategory::route('/{record}/edit'),
        ];
    }
}
