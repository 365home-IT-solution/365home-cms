<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReferralResource\Pages;

use App\Filament\Resources\ReferralResource;
use App\Filament\Support\GameFormFields;
use App\Models\ReferralSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReferrals extends ListRecords
{
    protected static string $resource = ReferralResource::class;

    protected function getHeaderActions(): array
    {
        $keys = ['is_enabled', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days', 'monthly_limit', 'apply_days', 'share_url_base'];

        return [
            Action::make('settings')
                ->label('Cài đặt giới thiệu')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Cài đặt giới thiệu bạn bè')
                ->modalSubmitActionLabel('Lưu')
                ->modalWidth('4xl')
                ->fillForm(fn () => ReferralSetting::current()->only($keys))
                ->form([
                    Toggle::make('is_enabled')->label('Bật giới thiệu bạn bè')->inline(false),

                    Grid::make(3)->schema([
                        ...GameFormFields::coupon(),
                        TextInput::make('monthly_limit')->label('Thưởng tối đa / tháng')->helperText('Số lần một người được thưởng trong tháng.')->numeric()->integer()->minValue(1)->maxValue(1000)->required(),
                        TextInput::make('apply_days')->label('Số ngày được nhập mã')->helperText('Kể từ khi tạo tài khoản.')->numeric()->integer()->minValue(1)->maxValue(90)->required(),
                    ]),

                    TextInput::make('share_url_base')->label('Link chia sẻ')->helperText('Ví dụ https://365home.vn/r — mã của khách được nối phía sau.')->url()->maxLength(255)->required(),
                ])
                ->action(function (array $data): void {
                    ReferralSetting::current()->update($data);
                    Notification::make()->title('Đã lưu cài đặt giới thiệu')->success()->send();
                }),
        ];
    }
}
