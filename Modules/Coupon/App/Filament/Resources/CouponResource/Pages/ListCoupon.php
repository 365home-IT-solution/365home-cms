<?php

declare(strict_types=1);

namespace Modules\Coupon\App\Filament\Resources\CouponResource\Pages;

use App\Settings\CouponSettings;
use Modules\Coupon\App\Filament\Resources\CouponResource;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCoupon extends ListRecords
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Luật chung cho mọi đơn — chỉ Super Admin (đối tác chỉ quản lý mã của mình). Xem App\Support\CouponStackLimit.
            Actions\Action::make('stackSettings')
                ->label('Cài đặt áp mã')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                ->modalHeading('Cài đặt áp mã')
                ->modalSubmitActionLabel('Lưu')
                ->fillForm(fn () => ['max_coupons_per_order' => app(CouponSettings::class)->max_coupons_per_order])
                ->form([
                    TextInput::make('max_coupons_per_order')
                        ->label('Tối đa mã giảm giá trong 1 đơn')
                        ->helperText('Tính mọi loại mã (Caro, hạng thành viên, khuyến mãi...). Mã độc quyền luôn chỉ dùng 1 mình. Bỏ trống = không giới hạn.')
                        ->numeric()->integer()->minValue(1)->maxValue(20),
                ])
                ->action(function (array $data): void {
                    $settings = app(CouponSettings::class);
                    $settings->max_coupons_per_order = filled($data['max_coupons_per_order']) ? (int) $data['max_coupons_per_order'] : null;
                    $settings->save();

                    Notification::make()->title('Đã lưu cài đặt áp mã')->success()->send();
                }),

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
