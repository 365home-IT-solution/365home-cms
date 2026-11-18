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
        $this->form->fill(['enabled' => $settings->isEnabled(), 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null]);
    }

    public function form(Form $form): Form
    {
        $info = app(MotrakSettingsService::class)->tokenInfo();
        $hint = match ($info['state']) {
            MotrakSettingsService::TOKEN_SAVED => 'Đã lưu một Token (••••' . ($info['hint'] ?? '') . '). Để trống ô này = giữ nguyên Token hiện tại; nhập Token mới = thay thế và tự kiểm tra kết nối.',
            MotrakSettingsService::TOKEN_UNREADABLE => 'Token đã lưu không đọc được (có thể do khoá mã hoá của máy chủ đã đổi). Hãy nhập lại Token.',
            default => 'Chưa có Token. Dán Bearer Token do Motrak cấp (không cần gõ chữ "Bearer").',
        };

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Kết nối API Motrak')->columns(2)->schema([
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

        if (($data['enabled'] ?? false) && $newToken === '' && $settings->tokenState() !== MotrakSettingsService::TOKEN_SAVED) {
            Notification::make()->danger()->title('Cần nhập Token trước khi bật đồng bộ tự động.')->send();

            return;
        }

        try {
            if ($newToken !== '') {
                $settings->saveToken($newToken);
                $settings->refresh();
            }
            $settings->update((bool) $data['enabled'], (int) $data['sync_interval_minutes']);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->form->fill(['enabled' => $settings->isEnabled(), 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null]);
        Notification::make()->success()->title('Đã lưu cấu hình Motrak')->send();

        // Token mới: kiểm tra kết nối ngay để người dùng biết Token dùng được hay chưa.
        if ($newToken !== '') {
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
            Action::make('clearToken')->label('Xoá Token')->icon('heroicon-o-trash')->color('danger')
                ->visible(fn () => app(MotrakSettingsService::class)->tokenState() !== MotrakSettingsService::TOKEN_NONE)
                ->requiresConfirmation()
                ->modalHeading('Xoá Token Motrak?')
                ->modalDescription('Đồng bộ tự động sẽ tắt. Dữ liệu GPS đã đồng bộ được giữ nguyên. Bạn có thể nhập Token mới bất cứ lúc nào.')
                ->action(function () {
                    $settings = app(MotrakSettingsService::class);
                    $settings->clearToken();
                    $settings->refresh();
                    $this->form->fill(['enabled' => false, 'sync_interval_minutes' => $settings->intervalMinutes(), 'token' => null]);
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
