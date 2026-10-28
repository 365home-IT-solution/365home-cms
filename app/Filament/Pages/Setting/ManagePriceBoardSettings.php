<?php

namespace App\Filament\Pages\Setting;

use App\Settings\PriceBoardSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Modules\Product\App\Models\PriceBoard;

// Bật / tắt GIAI ĐOẠN 2 của bảng giá ngay trên CMS (không cần sửa .env): bảng giá có độ ưu tiên + thứ áp dụng, giá theo từng thứ của bộ giá theo ngày, và giá đơn tính theo NGÀY khách ở.
class ManagePriceBoardSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = PriceBoardSettings::class;

    protected static ?int $navigationSort = 96;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Bảng giá — giai đoạn 2';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bảng giá';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Giai đoạn 2 của bảng giá')
                ->description('Bật: bảng giá có độ ưu tiên và các thứ áp dụng; bộ giá "Theo ngày" nhập được giá riêng cho từng thứ trong tuần (T2…CN); nhiều bảng chồng nhau theo ngày; giá đơn và báo giá được tính theo NGÀY KHÁCH Ở (không phải "giá hiện tại" của phòng). Tắt: mỗi phòng một giá, hai bảng đang bật trùng ngày cho cùng một phòng sẽ báo lỗi.')
                ->schema([
                    Forms\Components\Toggle::make('phase2')
                        ->label('Bật giai đoạn 2')
                        ->helperText('Đổi cách tính tiền của mọi đơn MỚI (đơn đã tạo giữ nguyên giá). Chỉ bật khi đã kiểm thử đặt phòng. Giá theo thứ đã nhập được giữ lại khi tắt, bật lại là dùng tiếp.')
                        ->columnSpanFull(),

                    Forms\Components\Placeholder::make('env_note')
                        ->hiddenLabel()
                        ->content(fn () => 'Trước khi chỉnh ở đây, hệ thống theo biến môi trường PRICE_BOARDS_PHASE2 của máy chủ (hiện: ' . (config('price_boards.phase2') ? 'BẬT' : 'TẮT') . '). Đã lưu ở đây thì cài đặt này được ưu tiên hơn.'),
                ]),
        ]);
    }

    // Chưa từng chỉnh ở CMS (null): hiện đúng trạng thái đang có hiệu lực để công tắc không hiển thị sai.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['phase2'] = PriceBoard::phase2Enabled();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['phase2'] = (bool) ($data['phase2'] ?? false);

        return $data;
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã lưu — ' . (PriceBoard::phase2Enabled() ? 'giai đoạn 2 đang BẬT' : 'giai đoạn 2 đang TẮT'));
    }
}
