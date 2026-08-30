<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources;

use App\Filament\Clusters\DiscountsCluster;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Coupon\App\Filament\Resources\CouponUsageLogResource\Pages;
use Modules\Coupon\App\Filament\Resources\CouponUsageLogResource\Tables\CouponUsageLogTable;
use Modules\Promotion\App\Models\CouponUsageLog;

// Trang chỉ-đọc "Lịch sử dùng mã giảm giá" — ghi lại đúng lúc mã giảm giá được xác nhận dùng (đơn
// thanh toán thành công lần đầu, xem CouponUsageLedger::confirm()), kèm nút Xuất Excel chọn khoảng
// ngày. Cùng khuôn với Modules\AuditLog\App\Filament\Resources\AuditLogResource (read-only, 1 page
// 'index' duy nhất).
class CouponUsageLogResource extends Resource
{
    protected static ?string $model = CouponUsageLog::class;

    protected static ?string $cluster = DiscountsCluster::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-receipt-percent';
    }

    public static function getNavigationLabel(): string
    {
        return 'Lịch sử dùng mã giảm giá';
    }

    public static function getModelLabel(): string
    {
        return 'Lịch sử dùng mã giảm giá';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Lịch sử dùng mã giảm giá';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $_): bool
    {
        return false;
    }

    public static function canDelete(mixed $_): bool
    {
        return false;
    }

    private static function hasViewPermission(): bool
    {
        $user = auth()->user();

        return $user?->hasRole(config('filament-shield.super_admin.name'))
            || $user?->can('view_any_coupon')
            || $user?->can('view_any_coupon::usage::log');
    }

    public static function canViewAny(): bool
    {
        return static::hasViewPermission();
    }

    public static function canAccess(): bool
    {
        return static::hasViewPermission();
    }

    // CouponUsageLog dùng BelongsToPartner nên parent::getEloquentQuery() đã tự lọc đúng phạm vi
    // đối tác cho tài khoản không phải super_admin — không cần thu hẹp gì thêm.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return CouponUsageLogTable::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCouponUsageLog::route('/'),
        ];
    }
}
