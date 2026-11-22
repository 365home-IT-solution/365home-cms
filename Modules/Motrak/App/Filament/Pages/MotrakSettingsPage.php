<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Services\MotrakSettingsService;
use Modules\Motrak\App\Services\MotrakStatusService;
use Modules\Motrak\App\Services\MotrakSyncService;
use Modules\Motrak\App\Support\MotrakAccess;

// Cấu hình Motrak (chỉ Super Admin): nhập/đổi/xoá Bearer Token, bật/tắt đồng bộ tự động, chu kỳ, kiểm tra kết nối. Token mã hoá khi lưu, KHÔNG BAO GIỜ
// hiện lại trên màn hình (chỉ 4 ký tự cuối), không vào log/exception. Token sai/hết hạn không làm hỏng CMS: nhập Token mới rồi kiểm tra lại ngay, không cần deploy.
class MotrakSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Cấu hình Motrak';

    protected static ?string $title = 'Cấu hình Motrak';

    protected static ?string $slug = 'gps-settings';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'motrak::pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return MotrakAccess::canConfigure(auth()->user());
    }

    public function mount(): void
    {
        $settings = app(MotrakSettingsService::class);
        $this->form->fill(['enabled' => $settings->isEnabled(), 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null, 'username' => $settings->credentialsInfo()['username'], 'password' => null]);
    }

    public function form(Form $form): Form
    {
        $info = app(MotrakSettingsService::class)->tokenInfo();
        $hint = match ($info['state']) {
            MotrakSettingsService::TOKEN_SAVED => 'Đã lưu một Token (••••' . ($info['hint'] ?? '') . '). Để trống ô này = giữ nguyên Token hiện tại; nhập Token mới = thay thế và tự kiểm tra kết nối.',
            MotrakSettingsService::TOKEN_UNREADABLE => 'Token đã lưu không đọc được (có thể do khoá mã hoá của máy chủ đã đổi). Hãy nhập lại Token.',
            default => 'Chưa có Token. Dán Bearer Token do Motrak cấp (không cần gõ chữ "Bearer").',
        };

        $creds = app(MotrakSettingsService::class)->credentialsInfo();
        $expires = $info['expires_at'] ? ' Token hiện tại hết hạn lúc ' . \Carbon\Carbon::parse($info['expires_at'])->format('H:i d/m/Y') . ' — hệ thống tự đăng nhập lấy Token mới trước khi hết hạn.' : '';

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Đăng nhập tự động (khuyên dùng)')->columns(2)
                ->description('Nhập tài khoản đăng nhập Motrak: hệ thống tự lấy Token và TỰ LẤY LẠI mỗi khi hết hạn (Token Motrak sống khoảng 1 giờ) — không cần dán Token thủ công nữa. Nên dùng một tài khoản Motrak riêng chỉ cho việc này.')
                ->schema([
                    Forms\Components\Placeholder::make('login_endpoint')->label('Endpoint đăng nhập (đã kiểm chứng — không chỉnh ở đây)')
                        ->content(fn () => 'POST ' . config('motrak.login_endpoint')),
                    Forms\Components\Placeholder::make('auto_state')->label('Trạng thái')
                        ->content($creds['saved'] ? 'Đã lưu tài khoản đăng nhập (mật khẩu mã hoá, không hiện lại).' . $expires : 'Chưa lưu tài khoản — đang dùng Token dán tay (hết hạn phải dán lại).'),
                    Forms\Components\TextInput::make('username')->label('Tên đăng nhập Motrak')->autocomplete(false)->maxLength(190),
                    Forms\Components\TextInput::make('password')->label('Mật khẩu')->password()->revealable()->autocomplete('new-password')->maxLength(200)
                        ->placeholder($creds['saved'] ? 'Để trống = giữ mật khẩu đang lưu' : null)
                        ->helperText('Mật khẩu chỉ lưu dạng mã hoá, không bao giờ hiện lại hay ghi vào log.'),
                ]),
            Forms\Components\Section::make('Kết nối API Motrak (Token dán tay — dự phòng)')->columns(2)->schema([
                Forms\Components\Placeholder::make('endpoint')->label('Endpoint (đã kiểm chứng — không chỉnh ở đây)')
                    ->content(fn () => 'POST ' . config('motrak.endpoint')),
                Forms\Components\TextInput::make('token')->label('Bearer Token')
                    ->password()->autocomplete(false)->maxLength(4200)->helperText($hint)
                    ->placeholder($info['state'] === MotrakSettingsService::TOKEN_SAVED ? 'Để trống = giữ nguyên Token' : 'Dán Token Motrak'),
            ]),
            Forms\Components\Section::make('Đồng bộ tự động')->columns(2)->schema([
                Forms\Components\Toggle::make('enabled')->label('Bật đồng bộ tự động')
                    ->helperText('Scheduler của Laravel gọi mỗi phút; hệ thống tự bỏ qua nếu chưa tới chu kỳ bên dưới. Cần cron chạy "php artisan schedule:run".'),
                Forms\Components\Select::make('sync_interval_minutes')->label('Chu kỳ đồng bộ')->native(false)->required()
                    ->options(collect((array) config('motrak.sync.allowed_intervals'))->mapWithKeys(fn ($m) => [$m => "{$m} phút"])->all())
                    ->helperText('Mặc định 1 phút. Một lần gọi lấy toàn bộ phương tiện của tài khoản Motrak.'),
            ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(MotrakSettingsService::class);
        $newToken = trim((string) ($data['token'] ?? ''));

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $wantsLogin = $username !== '' || $password !== '';

        if (($data['enabled'] ?? false) && $newToken === '' && ! $wantsLogin && $settings->tokenState() !== MotrakSettingsService::TOKEN_SAVED && ! $settings->hasCredentials()) {
            Notification::make()->danger()->title('Cần nhập tài khoản đăng nhập hoặc Token trước khi bật đồng bộ tự động.')->send();

            return;
        }

        try {
            if ($wantsLogin) {
                $settings->saveCredentials($username !== '' ? $username : (string) ($settings->credentialsInfo()['username'] ?? ''), $password);
                $settings->refresh();
            }
            if ($newToken !== '') {
                $settings->saveToken($newToken);
                $settings->refresh();
            }
            $settings->update((bool) $data['enabled'], (int) $data['sync_interval_minutes']);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->form->fill(['enabled' => $settings->isEnabled(), 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null, 'username' => $settings->credentialsInfo()['username'], 'password' => null]);
        Notification::make()->success()->title('Đã lưu cấu hình Motrak')->send();

        // Tài khoản / Token mới: kiểm tra kết nối ngay (có tài khoản thì tự đăng nhập lấy Token) để biết dùng được hay chưa.
        if ($newToken !== '' || $wantsLogin) {
            $this->runConnectionTest();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')->label('Kiểm tra kết nối')->icon('heroicon-o-signal')->color('gray')
                ->action(fn () => $this->runConnectionTest()),
            Action::make('syncNow')->label('Đồng bộ ngay')->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()->modalDescription('Gọi API Motrak ngay bây giờ để cập nhật danh sách và vị trí phương tiện.')
                ->action(function () {
                    $log = app(MotrakSyncService::class)->sync(MotrakSyncLog::TYPE_MANUAL, (string) auth()->id());
                    $n = Notification::make();
                    $log->isOk()
                        ? $n->success()->title('Đồng bộ xong')->body("{$log->success_count}/{$log->total_vehicles} xe, {$log->new_locations} điểm mới.")
                        : $n->danger()->title('Đồng bộ thất bại')->body(trim(($log->http_status ? "HTTP {$log->http_status}. " : '') . $log->error_message))->persistent();
                    $n->send();
                }),
            Action::make('clearCredentials')->label('Xoá tài khoản đăng nhập')->icon('heroicon-o-key')->color('gray')
                ->visible(fn () => app(MotrakSettingsService::class)->hasCredentials())
                ->requiresConfirmation()
                ->modalHeading('Xoá tài khoản đăng nhập Motrak?')
                ->modalDescription('Hệ thống ngừng tự lấy Token mới. Token đang có vẫn dùng tới khi hết hạn; sau đó cần dán Token tay hoặc lưu lại tài khoản.')
                ->action(function () {
                    $settings = app(MotrakSettingsService::class);
                    $settings->clearCredentials();
                    $settings->refresh();
                    $this->form->fill(['enabled' => $settings->isEnabled(), 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null, 'username' => null, 'password' => null]);
                    Notification::make()->success()->title('Đã xoá tài khoản đăng nhập')->send();
                }),
            Action::make('clearToken')->label('Xoá Token')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => app(MotrakSettingsService::class)->tokenState() !== MotrakSettingsService::TOKEN_NONE)
                ->requiresConfirmation()
                ->modalHeading('Xoá Token Motrak?')
                ->modalDescription('Đồng bộ tự động sẽ tắt. Dữ liệu GPS đã đồng bộ được giữ nguyên. Bạn có thể nhập Token mới bất cứ lúc nào.')
                ->action(function () {
                    $settings = app(MotrakSettingsService::class);
                    $settings->clearToken();
                    $settings->refresh();
                    $this->form->fill(['enabled' => false, 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null, 'username' => $settings->credentialsInfo()['username'], 'password' => null]);
                    Notification::make()->success()->title('Đã xoá Token')->send();
                }),
        ];
    }

    /** Gọi API thật với Token đã lưu và báo kết quả: thành công (số xe) hoặc lỗi kèm mã HTTP + loại lỗi — không bao giờ hiện Token. */
    private function runConnectionTest(): void
    {
        $log = app(MotrakSyncService::class)->testConnection((string) auth()->id());
        $n = Notification::make();

        if ($log->isOk()) {
            $n->success()->title('Kết nối thành công')->body("Nhận được {$log->total_vehicles} phương tiện lúc " . now()->format('H:i:s d/m/Y') . '.');
        } else {
            $status = app(MotrakStatusService::class)->status();
            $n->danger()->title($status['label'])->body(trim(($log->http_status ? "HTTP {$log->http_status}. " : '') . $log->error_message))->persistent();
        }
        $n->send();
    }

    protected function getViewData(): array
    {
        return [
            'connection' => app(MotrakStatusService::class)->status(),
            'tokenInfo'  => app(MotrakSettingsService::class)->tokenInfo(),
        ];
    }
}
