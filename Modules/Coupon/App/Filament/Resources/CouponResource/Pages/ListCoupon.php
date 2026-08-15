<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCoupon extends ListRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tạo mã giảm giá mới')
                ->icon('heroicon-o-plus'),
        ];
    }

    // Tab đầu = mặc định (Filament tự chọn key đầu tiên trong mảng làm active tab).
    // 'customer_id' NOT NULL = voucher đã gắn cho 1 khách cụ thể (cấp tay hoặc bản sao tự động cấp
    // theo hạng thành viên, xem MembershipService::grantTemplateCoupon()). 'customer_id' NULL = mã
    // dùng chung admin tạo cho khuyến mãi chung (bao gồm cả coupon MẪU gắn vào hạng thành viên).
    public function getTabs(): array
    {
        return [
            'customer' => Tab::make('Voucher khách hàng')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('customer_id'))
                ->badge(fn () => static::getResource()::getEloquentQuery()->whereNotNull('customer_id')->count()),

            'admin' => Tab::make('Admin tạo')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('customer_id'))
                ->badge(fn () => static::getResource()::getEloquentQuery()->whereNull('customer_id')->count()),
        ];
    }
}
