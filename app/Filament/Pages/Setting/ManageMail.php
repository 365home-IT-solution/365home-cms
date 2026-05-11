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

        $data = $this->mutateFormDataBeforeFill(app(static::getSettings())->toArray());

        $this->form->fill($data);

        $this->callHook('afterFill');
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
        return __("Cấu hình web");
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
