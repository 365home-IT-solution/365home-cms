<?php

namespace App\Filament\Pages\Setting;

use App\Settings\ManualPaymentSettings;
use App\Support\ManualPaymentConfig;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;

// Cấu hình "xác nhận thủ công" — đối tác không dùng cổng (SePay / Pay2S): khách chuyển khoản vào tài khoản đối tác, đối tác tự xem app ngân hàng rồi bấm xác nhận.
// Giá trị nhập ở đây được ưu tiên hơn MANUAL_PAYMENT_* trong .env; để trống = theo .env.
class ManageManualPaymentSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = ManualPaymentSettings::class;

    protected static ?int $navigationSort = 97;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $title = 'Xác nhận thủ công — cấu hình';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Xác nhận thủ công — cấu hình';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    private static function sourceLabel(string $key): string
    {
        return match (ManualPaymentConfig::source($key)) {
            'cms' => 'đang dùng giá trị nhập ở CMS',
            'env' => 'chưa nhập ở CMS — đang dùng .env của máy chủ',
            default => 'CHƯA cấu hình',
        };
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thời gian giữ đơn')
                ->description('Không có cổng báo tiền về nên đối tác cần thời gian đối chiếu app ngân hàng — đơn được giữ lâu hơn 15 phút của cổng tự động. Hết hạn mà đối tác chưa xác nhận thì đơn tự huỷ. Đối tác nào dùng xác nhận thủ công do mục "SePay / Pay2S theo đối tác" (cổng "Xác nhận thủ công") quyết định.')
                ->schema([
                    Forms\Components\TextInput::make('hold_minutes')
                        ->label('Giữ đơn tối thiểu (phút)')
                        ->numeric()->integer()->minValue(15)->maxValue(1440)
                        ->placeholder('60')
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('hold_minutes') . ' (mặc định 60 phút).'),
                    Forms\Components\TextInput::make('claimed_hold_minutes')
                        ->label('Giữ thêm khi khách báo đã chuyển khoản (phút)')
                        ->numeric()->integer()->minValue(15)->maxValue(2880)
                        ->placeholder('240')
                        ->helperText(fn () => 'Trạng thái: ' . self::sourceLabel('claimed_hold_minutes') . ' (mặc định 240 phút, tính từ lúc khách bấm "Tôi đã chuyển khoản").'),
                ])->columns(2),
        ]);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (['hold_minutes', 'claimed_hold_minutes'] as $key) {
            $data[$key] = filled($data[$key] ?? null) ? (int) $data[$key] : null;
        }

        return $data;
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã lưu cấu hình xác nhận thủ công');
    }
}
