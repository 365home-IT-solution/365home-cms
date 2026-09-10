<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\SurchargeResource\Forms\SurchargeForm;
use Modules\Minihouse\App\Filament\Resources\SurchargeResource\Pages;
use Modules\Minihouse\App\Filament\Resources\SurchargeResource\Tables\SurchargeTable;
use Modules\Minihouse\App\Models\Surcharge;

class SurchargeResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Surcharge::class;
    protected static ?string $navigationIcon  = 'heroicon-o-receipt-percent';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Phụ thu';
    // Đứng sau "Tiện ích" (sort=3) — cùng nhóm dữ liệu phụ trợ cho Toà nhà/Phòng.
    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string { return 'Phụ thu'; }
    public static function getPluralModelLabel(): string { return 'Phụ thu'; }

    public static function permissionGroup(): string
    {
        // Dùng chung quyền với "Toà nhà" — phụ thu là dữ liệu phụ trợ theo toà, không cần bộ quyền
        // riêng (giống cách AmenityResource dùng chung quyền "rooms").
        return 'buildings';
    }

    public static function form(Form $form): Form { return SurchargeForm::form($form); }
    public static function table(Table $table): Table { return SurchargeTable::table($table); }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSurcharges::route('/'),
            'create' => Pages\CreateSurcharge::route('/create'),
            'edit'   => Pages\EditSurcharge::route('/{record}/edit'),
        ];
    }
}
