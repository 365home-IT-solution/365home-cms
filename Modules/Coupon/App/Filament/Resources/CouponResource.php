<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources;

use Modules\Coupon\App\Filament\Resources\CouponResource\Forms\CouponForm;
use Modules\Coupon\App\Filament\Resources\CouponResource\Tables\CouponTable;
use Modules\Coupon\App\Filament\Resources\CouponResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Promotion\App\Models\Coupon;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = 'Khuyến mãi';

    public static function getNavigationLabel(): string
    {
        return 'Mã giảm giá';
    }

    public static function getModelLabel(): string
    {
        return 'Coupon';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Coupon';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_coupon') ?? false;
    }

    public static function form(Form $form): Form
    {
        return CouponForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return CouponTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupon::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
