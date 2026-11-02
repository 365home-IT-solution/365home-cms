<?php

namespace App\Filament\Pages\Setting;

use App\Settings\CashPaymentSettings;
use App\Support\CashPaymentConfig;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;

// Cấu hình thanh toán tiền mặt tại quầy: giờ tự huỷ khi khách không đến và ngưỡng chống huỷ đơn để "đi cửa sau" (xem CancellationGuardService).
// Giá trị nhập ở đây được ưu tiên hơn CASH_* trong .env; để trống = theo .env.
class ManageCashPaymentSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = CashPaymentSettings::class;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Tiền mặt tại quầy — cấu hình';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Tiền mặt tại quầy — cấu hình';
    }

    public static function canAccess(): bool
    {
        return (auth()->user()?->isSuperAdmin() ?? false) && parent::canAccess();
    }

    private static function hint(string $key, string $default): string
    {
        return 'Trạng thái: ' . match (CashPaymentConfig::source($key)) {
            'cms' => 'đang dùng giá trị nhập ở CMS',
            'env' => 'chưa nhập ở CMS — đang dùng .env của máy chủ',
            default => 'CHƯA cấu hình',
        } . ' (mặc định ' . $default . ').';
    }

    public function form(Form $form): Form
    {
        $limit = fn (string $key, string $label) => Forms\Components\TextInput::make($key)->label($label)
            ->numeric()->integer()->minValue(0)->maxValue(1000)->placeholder((string) config('cash_payment.' . $key))
            ->helperText(fn () => self::hint($key, (string) config('cash_payment.' . $key)) . ' 0 = không giới hạn.');

        return $form->schema([
            Forms\Components\Section::make('Khách không đến')
                ->description('Đơn tiền mặt chưa thu tiền và khách chưa nhận phòng: quá giờ nhận phòng số giờ này thì tự huỷ để nhả phòng. Khách đã nhận phòng thì không huỷ.')
                ->schema([
                    Forms\Components\TextInput::make('no_show_hours')->label('Tự huỷ sau giờ nhận phòng (giờ)')
                        ->numeric()->integer()->minValue(1)->maxValue(72)->placeholder('3')
                        ->helperText(fn () => self::hint('no_show_hours', '3 giờ')),
                ]),

            Forms\Components\Section::make('Chống huỷ đơn để đi "cửa sau"')
                ->description('Đếm đơn tiền mặt bị huỷ theo NGÀY và THÁNG. Đối tác: chỉ đếm đơn do chính tài khoản của đối tác huỷ; đạt ngưỡng thì tạm khoá mọi phòng đang đăng, đối tác không tự mở lại được — Super Admin mở khoá ở trang sửa đối tác. Khách (cùng số điện thoại): đếm mọi đơn tiền mặt bị huỷ của khách; đạt ngưỡng thì chỉ thông báo cho Super Admin và đối tác, không khoá.')
                ->schema([
                    Forms\Components\Toggle::make('guard_enabled')->label('Bật kiểm tra tỷ lệ huỷ')->columnSpanFull(),
                    $limit('partner_day_limit', 'Đối tác: số đơn huỷ / ngày'),
                    $limit('partner_month_limit', 'Đối tác: số đơn huỷ / tháng'),
                    $limit('customer_day_limit', 'Khách: số đơn huỷ / ngày (chỉ thông báo)'),
                    $limit('customer_month_limit', 'Khách: số đơn huỷ / tháng (chỉ thông báo)'),
                ])->columns(2),
        ]);
    }

    // Công tắc hiện đúng trạng thái đang có hiệu lực (kể cả khi đang theo .env).
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['guard_enabled'] = (bool) CashPaymentConfig::get('guard_enabled', true);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (array_diff(CashPaymentConfig::KEYS, ['guard_enabled']) as $key) {
            $data[$key] = filled($data[$key] ?? null) ? (int) $data[$key] : null;
        }
        $data['guard_enabled'] = (bool) ($data['guard_enabled'] ?? false);

        return $data;
    }

    public function getSavedNotification(): ?Notification
    {
        return Notification::make()->success()->title('Đã lưu cấu hình tiền mặt tại quầy');
    }
}
