<?php

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\ZoneResource\Forms\ZoneForm;
use Modules\Minihouse\App\Filament\Resources\ZoneResource\Pages;
use Modules\Minihouse\App\Filament\Resources\ZoneResource\Tables\ZoneTable;
use Modules\Minihouse\App\Models\Zone;

// "Khu vực" — gộp nhóm nhiều Toà nhà (xem Zone::class). Đứng CÙNG mục "Toà nhà" trong menu điều
// hướng (navigationSort liền kề) vì đây là dữ liệu quản trị cùng nhóm, không phải nghiệp vụ vận hành
// hàng ngày như Phòng/Hợp đồng/Hoá đơn.
class ZoneResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Zone::class;
    protected static ?string $navigationIcon  = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Khu vực';
    protected static ?int $navigationSort     = 0;

    public static function getModelLabel(): string
    {
        return 'Khu vực';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Khu vực';
    }

    public static function permissionGroup(): string
    {
        return 'zones';
    }

    public static function form(Form $form): Form
    {
        return ZoneForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return ZoneTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListZones::route('/'),
            'create' => Pages\CreateZone::route('/create'),
            'edit'   => Pages\EditZone::route('/{record}/edit'),
        ];
    }
}
