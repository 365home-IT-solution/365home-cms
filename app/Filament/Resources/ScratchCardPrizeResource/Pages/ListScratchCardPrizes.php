<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScratchCardPrizeResource\Pages;

use App\Filament\Resources\ScratchCardPrizeResource;
use App\Models\ScratchCardPrize;
use App\Models\ScratchCardSetting;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListScratchCardPrizes extends ListRecords
{
    protected static string $resource = ScratchCardPrizeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Cài đặt thẻ cào')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Cài đặt thẻ cào')
                ->modalSubmitActionLabel('Lưu')
                ->fillForm(fn () => ScratchCardSetting::current()->only(['is_enabled', 'expire_days', 'min_stay_days', 'min_order_amount', 'rating_bonus_enabled']))
                ->form([
                    Toggle::make('is_enabled')
                        ->label('Bật mini game thẻ cào')
                        ->helperText('Bật cần có 1 giải dự phòng (không giới hạn lượt) đang bật, nếu không hết ngân sách khách sẽ cào ra "may mắn lần sau" mãi.')
                        ->inline(false),

                    Grid::make(3)->schema([
                        TextInput::make('expire_days')->label('Hạn cào (ngày)')->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                        TextInput::make('min_stay_days')->label('Số ngày ở tối thiểu')->helperText('0 = trả phòng là có thẻ.')->numeric()->integer()->minValue(0)->maxValue(365)->required(),
                        TextInput::make('min_order_amount')->label('Đơn tối thiểu (VNĐ)')->helperText('0 = không yêu cầu.')->numeric()->integer()->minValue(0)->required(),
                    ]),

                    Toggle::make('rating_bonus_enabled')->label('Tặng thẻ khi đánh giá có ảnh')->inline(false),
                ])
                ->action(function (array $data, Action $action): void {
                    if (($data['is_enabled'] ?? false) && ! ScratchCardPrize::where('is_fallback', true)->where('is_active', true)->exists()) {
                        Notification::make()->title('Cần có 1 giải dự phòng đang bật trước khi bật thẻ cào.')->danger()->send();
                        $action->halt();
                    }

                    ScratchCardSetting::current()->update($data);
                    Notification::make()->title('Đã lưu cài đặt thẻ cào')->success()->send();
                }),

            CreateAction::make()->label('Thêm giải'),
        ];
    }
}
