<?php

namespace App\Filament\Pages\Setting;

use App\Mail\TestMail;
use App\Settings\MailSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use JulioMotol\FilamentPasswordConfirmation\RequiresPasswordConfirmation;

use function Filament\Support\is_app_url;

class ManageMail extends SettingsPage
{
    use HasPageShield,
        RequiresPasswordConfirmation;

    protected static string $settings = MailSettings::class;

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        // MailSettings mã hoá username/password bằng APP_KEY lúc lưu — nếu database hiện tại (vd
        // vừa import từ máy/server khác, hoặc APP_KEY vừa đổi) có dữ liệu mail mã hoá bằng 1
        // APP_KEY KHÁC, app(MailSettings::class)->toArray() sẽ ném DecryptException ngay khi load,
        // làm SẬP CẢ TRANG trước khi kịp hiển thị form để nhập lại — nhân viên không còn cách nào
        // vào trang này để sửa. Bọc try/catch: giải mã lỗi thì coi như username/password CHƯA
        // NHẬP (rỗng), các trường còn lại (host, port...) không mã hoá nên vẫn hiển thị đúng bình
        // thường — trang luôn vào được, nhập lại xong Lưu là hết lỗi hẳn (ghi đè bằng APP_KEY hiện
        // tại), không cần ai phải sửa tay dưới database nữa.
        try {
            $data = app(static::getSettings())->toArray();
        } catch (\Throwable $e) {
            Log::warning('ManageMail: không giải mã được mail settings hiện có (APP_KEY không khớp dữ liệu đã lưu) — hiển thị form trống để nhập lại.', [
                'error' => $e->getMessage(),
            ]);

            $data = $this->loadMailSettingsIgnoringDecryptFailure();
        }

        $data = $this->mutateFormDataBeforeFill($data);

        $this->form->fill($data);

        $this->callHook('afterFill');
    }

    // Đọc thẳng bảng settings (group=mail), tự bỏ qua riêng từng trường mã hoá (username/password)
    // nếu giải mã lỗi — thay vì để 1 trường lỗi làm hỏng luôn toàn bộ dữ liệu còn lại.
    private function loadMailSettingsIgnoringDecryptFailure(): array
    {
        $rows = DB::table('settings')->where('group', 'mail')->pluck('payload', 'name');

        $decode = function (string $name, mixed $default = null) use ($rows) {
            if (! isset($rows[$name])) {
                return $default;
            }

            return json_decode($rows[$name], true) ?? $default;
        };

        $decryptSafely = function (string $name) use ($rows) {
            if (! isset($rows[$name])) {
                return null;
            }

            $encrypted = json_decode($rows[$name], true);

            if (blank($encrypted)) {
                return null;
            }

            try {
                // decrypt() (unserialize=true, mặc định) — KHÔNG dùng decryptString() — vì
                // Spatie\LaravelSettings\Support\Crypto::encrypt() dùng helper encrypt() gốc
                // (có serialize), phải giải mã đúng cặp bằng decrypt()/Crypt::decrypt() mới ra
                // đúng chuỗi gốc, decryptString() sẽ trả về dạng còn serialize dở (vd s:5:"abc";).
                return Crypt::decrypt($encrypted);
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'driver'             => $decode('driver'),
            'host'               => $decode('host'),
            'port'               => $decode('port', 0),
            'encryption'         => $decode('encryption'),
            'timeout'            => $decode('timeout'),
            'local_domain'       => $decode('local_domain'),
            'from_address'       => $decode('from_address'),
            'from_name'          => $decode('from_name'),
            'lock_notify_emails' => $decode('lock_notify_emails', []),
            // 2 trường này là nguồn gốc lỗi — giải mã riêng từng trường, lỗi thì để trống.
            'username'           => $decryptSafely('username'),
            'password'           => $decryptSafely('password'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('Cấu hình')
                            ->label(fn() => __('page.mail_settings.sections.config.title'))
                            ->icon('heroicon-o-at-symbol')
                            ->schema([
                                Forms\Components\Grid::make()
                                    ->schema([
                                        Forms\Components\Select::make('driver')->label(fn() => __('page.mail_settings.fields.driver'))
                                            ->options([
                                                "smtp" => "SMTP (Recommended)",
                                                "mailgun" => "Mailgun",
                                                "ses" => "Amazon SES",
                                                "postmark" => "Postmark",
                                            ])
                                            ->native(false)
                                            ->required()
                                            ->columnSpan(2),
                                        Forms\Components\TextInput::make('host')->label(fn() => __('page.mail_settings.fields.host'))
                                            ->required(),
                                        Forms\Components\TextInput::make('port')->label(fn() => __('page.mail_settings.fields.port')),
                                        Forms\Components\Select::make('encryption')->label(fn() => __('page.mail_settings.fields.encryption'))
                                            ->options([
                                                "ssl" => "SSL",
                                                "tls" => "TLS",
                                            ])
                                            ->native(false),
                                        Forms\Components\TextInput::make('timeout')->label(fn() => __('page.mail_settings.fields.timeout')),
                                        Forms\Components\TextInput::make('username')->label(fn() => __('page.mail_settings.fields.username')),
                                        Forms\Components\TextInput::make('password')->label(fn() => __('page.mail_settings.fields.password'))
                                            ->password()
                                            ->revealable(),
                                    ])
                                    ->columns(3),
                            ])
                    ])
                    ->columnSpan([
                        "md" => 2
                    ]),
                Forms\Components\Group::make()
                    ->schema([
                        Forms\Components\Section::make('Từ (Người gửi)')
                            ->label(fn() => __('page.mail_settings.section.sender.title'))
                            ->icon('heroicon-o-at-symbol')
                            ->schema([
                                Forms\Components\TextInput::make('from_address')->label(fn() => __('page.mail_settings.fields.email'))
                                    ->required(),
                                Forms\Components\TextInput::make('from_name')->label(fn() => __('page.mail_settings.fields.name'))
                                    ->required(),
                            ]),

                        Forms\Components\Section::make('Mail đến')
                            ->label(fn() => __('page.mail_settings.section.mail_to.title'))
                            ->schema([
                                Forms\Components\TextInput::make('mail_to')
                                    ->label(fn() => __('page.mail_settings.fields.mail_to'))
                                    ->hiddenLabel()
                                    ->placeholder(fn() => __('page.mail_settings.fields.placeholder.receiver_email')),
                                Forms\Components\Actions::make([
                                    Forms\Components\Actions\Action::make('Send Test Mail')
                                        ->label(fn() => __('page.mail_settings.actions.send_test_mail'))
                                        ->action('sendTestMail')
                                        ->color('warning')
                                        ->icon('heroicon-o-at-symbol')
                                ])->fullWidth(),
                            ]),

                        Forms\Components\Section::make('Thông báo Check-in / Check-out')
                            ->icon('heroicon-o-bell')
                            ->schema([
                                Forms\Components\TagsInput::make('lock_notify_emails')
                                    ->label('Email nhận thông báo mở khóa')
                                    ->placeholder('Nhập email rồi nhấn Enter...')
                                    ->helperText('Nhập từng địa chỉ email rồi nhấn Enter để thêm. Hệ thống sẽ gửi email đến tất cả khi có sự kiện check-in / check-out.'),
                            ])
                    ])
                    ->columnSpan([
                        "md" => 1
                    ]),
            ])
            ->columns(3)
            ->statePath('data');
    }

    public function save(MailSettings $settings = null): void
    {
        try {
            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeSave($data);

            $this->callHook('beforeSave');

            $settings->fill($data);

            $settings->save();

            $this->callHook('afterSave');

            $this->sendSuccessNotification('Đã cập nhật Mail.');

            $this->redirect(static::getUrl(), navigate: FilamentView::hasSpaMode() && is_app_url(static::getUrl()));
        } catch (\Throwable $th) {
            $this->sendErrorNotification('Không cập nhật được cài đặt. ' . $th->getMessage());
            throw $th;
        }
    }

    public function sendTestMail(MailSettings $settings = null)
    {
        $data = $this->form->getState();

        $settings->loadMailSettingsToConfig($data);
        try {
            $mailTo = $data['mail_to'];
            $mailData = [
                'title' => 'Đây là email thử nghiệm để xác minh cài đặt SMTP',
                'body' => 'Đây là cách kiểm tra email bằng smtp.'
            ];

            Mail::to($mailTo)->send(new TestMail($mailData));

            $this->sendSuccessNotification('Mail được gửi tới: ' . $mailTo);
        } catch (\Exception $e) {
            $this->sendErrorNotification($e->getMessage());
        }
    }

    public function sendSuccessNotification($title)
    {
        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }

    public function sendErrorNotification($title)
    {
        Notification::make()
            ->title($title)
            ->danger()
            ->send();
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return __("page.mail_settings.navigationLabel");
    }

    public function getTitle(): string|Htmlable
    {
        return __("page.mail_settings.title");
    }

    public function getHeading(): string|Htmlable
    {
        return __("page.mail_settings.heading");
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __("page.mail_settings.subheading");
    }
}
