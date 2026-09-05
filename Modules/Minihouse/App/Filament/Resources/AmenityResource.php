<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\AmenityResource\Forms\AmenityForm;
use Modules\Minihouse\App\Filament\Resources\AmenityResource\Pages;
use Modules\Minihouse\App\Filament\Resources\AmenityResource\Tables\AmenityTable;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Models\Amenity;

class AmenityResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Amenity::class;
    protected static ?string $navigationIcon  = 'heroicon-o-sparkles';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Tiện ích';
    // Đứng ngay sau "Phòng" (sort=2) — tiện ích là dữ liệu phụ trợ cho phòng, không cần tách xa.
    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'Tiện ích';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Tiện ích';
    }

    public static function permissionGroup(): string
    {
        // Dùng chung quyền với "Phòng" — tiện ích là dữ liệu phụ trợ, không cần bộ quyền riêng.
        return 'rooms';
    }

    public static function form(Form $form): Form
    {
        return AmenityForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return AmenityTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAmenities::route('/'),
            'create' => Pages\CreateAmenity::route('/create'),
            'edit'   => Pages\EditAmenity::route('/{record}/edit'),
        ];
    }
}
