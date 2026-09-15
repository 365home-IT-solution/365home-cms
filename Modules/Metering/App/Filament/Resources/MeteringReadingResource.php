<?php

namespace Modules\Metering\App\Filament\Resources;

use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Metering\App\Filament\Resources\MeteringReadingResource\Forms\MeteringReadingForm;
use Modules\Metering\App\Filament\Resources\MeteringReadingResource\Pages;
use Modules\Metering\App\Filament\Resources\MeteringReadingResource\Tables\MeteringReadingTable;
use Modules\Metering\App\Models\MeteringReading;
use Modules\Minihouse\App\Filament\Resources\Concerns\AuthorizesByPermission;

// Resource quản lý log chỉ số điện/nước — module Metering tách riêng khỏi Minihouse (đơn giá điện/
// nước vẫn giữ nguyên bên Minihouse, xem Building/Contract). Dùng chung cơ chế phân quyền
// (AuthorizesByPermission) và nhóm quyền 'rooms' đã có sẵn của Minihouse — không cần bộ quyền riêng,
// giống cách SurchargeResource/AmenityResource dùng chung quyền của nhóm khác.
class MeteringReadingResource extends Resource
{
    use AuthorizesByPermission;

    protected static ?string $model = MeteringReading::class;
    protected static ?string $navigationIcon  = 'heroicon-o-bolt';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Số điện nước';
    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string { return 'Số điện nước'; }
    public static function getPluralModelLabel(): string { return 'Số điện nước'; }

    public static function permissionGroup(): string
    {
        return 'rooms';
    }

    public static function form(Form $form): Form { return MeteringReadingForm::form($form); }
    public static function table(Table $table): Table { return MeteringReadingTable::table($table); }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMeteringReadings::route('/'),
            'create' => Pages\CreateMeteringReading::route('/create'),
            'edit'   => Pages\EditMeteringReading::route('/{record}/edit'),
        ];
    }
}
