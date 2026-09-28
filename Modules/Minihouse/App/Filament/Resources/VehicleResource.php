<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;
use Modules\Minihouse\App\Filament\Resources\VehicleResource\Forms\VehicleForm;
use Modules\Minihouse\App\Filament\Resources\VehicleResource\Pages;
use Modules\Minihouse\App\Filament\Resources\VehicleResource\Tables\VehicleTable;
use Modules\Minihouse\App\Models\Vehicle;

// Xe của khách thuê: nhân viên tự thêm, hoặc khách tự khai trên Portal rồi nhân viên duyệt. Phí gửi
// xe theo bảng giá của từng Toà nhà (ManageVehicleRates) tự vào hoá đơn — xem VehicleService.
class VehicleResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon  = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Xe khách thuê';
    protected static ?int    $navigationSort  = 12;

    public static function getModelLabel(): string { return 'Xe khách thuê'; }
    public static function getPluralModelLabel(): string { return 'Xe khách thuê'; }

    public static function permissionGroup(): string
    {
        return 'vehicles';
    }

    // Badge số xe khách vừa tự khai đang chờ duyệt.
    public static function getNavigationBadge(): ?string
    {
        try {
            $count = Vehicle::query()->where('status', Vehicle::STATUS_PENDING)->count();
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form { return VehicleForm::form($form); }
    public static function table(Table $table): Table { return VehicleTable::table($table); }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit'   => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
