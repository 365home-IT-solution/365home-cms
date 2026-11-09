<?php

declare(strict_types=1);

namespace App\Filament\Resources\TienLenMatchResource\Pages;

use App\Filament\Resources\TienLenCardDeckResource;
use App\Filament\Resources\TienLenMatchResource;
use App\Models\GameTesterSetting;
use App\Models\TienLenSetting;
use App\Services\TienLenDeckService;
use Filament\Actions\Action;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTienLenMatches extends ListRecords
{
    protected static string $resource = TienLenMatchResource::class;

    protected function getHeaderActions(): array
    {
        $points = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->integer()->minValue(0)->maxValue(100000)->required();

        return [
            // Trang chơi thử /tien-len-tester — chỉ hiện khi đã bật trang thử (Quản lý API → Thử mini game)
            Action::make('tester')
                ->label('Mở trang chơi thử')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn () => route('tien-len-tester'), shouldOpenInNewTab: true)
                ->visible(fn () => GameTesterSetting::enabled()),

            Action::make('cardDecks')
                ->label('Bộ bài')
                ->icon('heroicon-o-rectangle-stack')
                ->color('gray')
                ->url(fn () => TienLenCardDeckResource::getUrl('index')),

            Action::make('settings')
                ->label('Cài đặt Tiến lên')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Cài đặt Tiến lên')
                ->modalSubmitActionLabel('Lưu')
                ->fillForm(fn () => TienLenSetting::current()->only(array_keys(TienLenSetting::rules())))
                ->form([
                    Toggle::make('is_enabled')->label('Bật mini game Tiến lên'),

                    TextInput::make('turn_seconds')->label('Thời gian mỗi lượt (giây)')
                        ->helperText('Quá giờ hệ thống tự bỏ lượt, hoặc tự đánh lá nhỏ nhất nếu đang mở vòng.')
                        ->numeric()->integer()->minValue(10)->maxValue(120)->required(),

                    Grid::make(4)->schema([
                        $points('points_first', 'Điểm về nhất'),
                        $points('points_second', 'Điểm về nhì'),
                        $points('points_third', 'Điểm về ba'),
                        $points('points_last', 'Điểm về bét'),
                    ]),

                    Grid::make(2)->schema([
                        $points('chop_bonus_points', 'Thưởng mỗi lần chặt'),
                        TextInput::make('daily_points_cap')->label('Trần điểm / ngày')->helperText('0 = không giới hạn.')
                            ->numeric()->integer()->minValue(0)->maxValue(10000000)->required(),
                    ]),

                    Select::make('default_card_deck')->label('Bộ bài mặc định')
                        ->helperText('Áp dụng cho khách chưa tự chọn bộ bài trong app.')
                        ->options(TienLenDeckService::options())->native(false)->required(),
                ])
                ->action(function (array $data): void {
                    TienLenSetting::current()->update($data);
                    Notification::make()->title('Đã lưu cài đặt Tiến lên')->success()->send();
                }),
        ];
    }
}
