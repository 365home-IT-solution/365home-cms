<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Forms;

use Filament\Forms\Form;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Settings\MailSettings;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\VerifyEmail;
use Filament\Notifications\Notification;

class UserForm
{
    private const COLUMN_SPAN = [
        'sm' => 1,
        'lg' => 2
    ];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                self::createLeftColumn(),
                self::createMainTabs()
            ])
            ->columns(3);
    }

    private static function createLeftColumn(): Forms\Components\Group
    {
        return Forms\Components\Group::make()
            ->schema([
                self::createAvatarUpload(),
                self::createVerificationAction(),
                self::createPasswordSection(),
                self::createTimestampsSection(),
            ])
            ->columnSpan(1);
    }

    private static function createAvatarUpload(): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make('media')
            ->hiddenLabel()
            ->avatar()
            ->collection('avatars')
            ->alignCenter()
            ->columnSpanFull();
    }

    private static function createVerificationAction(): Forms\Components\Actions
    {
        return Forms\Components\Actions::make([
            Action::make('resend_verification')
                ->label(__('resource.user.actions.resend_verification'))
                ->color('info')
                ->action(fn(MailSettings $settings, Model $record) => static::doResendEmailVerification($settings, $record))
        ])
            ->hiddenOn('create')
            ->fullWidth();
    }

    private static function createPasswordSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                self::createPasswordField(),
                self::createPasswordConfirmationField(),
            ])
            ->compact()
            ->hidden(fn(string $operation): bool => $operation === 'edit');
    }

    private static function createPasswordField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('password')
            ->label(__('user::user.form.label.password'))
            ->placeholder(__('user::user.form.placeholder.password'))
            ->password()
            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
            ->dehydrated(fn(?string $state): bool => filled($state))
            ->revealable()
            ->required()
            ->rules(['min:8']);
    }

    private static function createPasswordConfirmationField(): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make('passwordConfirmation')
            ->label(__('user::user.form.label.password_confirmation'))
            ->placeholder(__('user::user.form.placeholder.password_confirmation'))
            ->password()
            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
            ->dehydrated(fn(?string $state): bool => filled($state))
            ->revealable()
            ->same('password')
            ->required();
    }

    private static function createTimestampsSection(): Forms\Components\Section
    {
        return Forms\Components\Section::make()
            ->schema([
                self::createTimestampPlaceholder('email_verified_at', 'email_verified_at', fn(User $record) => new HtmlString("$record->email_verified_at")),
                self::createTimestampPlaceholder('created_at', 'created_at', fn(User $record) => $record->created_at?->diffForHumans()),
                self::createTimestampPlaceholder('updated_at', 'updated_at', fn(User $record) => $record->updated_at?->diffForHumans()),
            ])
            ->compact()
            ->hidden(fn(string $operation): bool => $operation === 'create');
    }

    private static function createTimestampPlaceholder(string $name, string $label, callable $contentCallback): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make($name)
            ->label(__("resource.general.$label"))
            ->content($contentCallback);
    }

    private static function createMainTabs(): Forms\Components\Tabs
    {
        return Forms\Components\Tabs::make()
            ->schema([
                self::createDetailsTab(),
                self::createRolesTab(),
            ])
            ->columnSpan(self::COLUMN_SPAN);
    }

    private static function createDetailsTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Chi tiết')
            ->icon('heroicon-o-information-circle')
            ->schema([
                self::createUniqueField('email')->email(),
                Forms\Components\TextInput::make('fullname')
                    ->label(__('user::user.form.label.fullname'))
                    ->placeholder(__('user::user.form.placeholder.fullname'))
                    ->required()
                    ->maxLength(255),
            ])
            ->columns(2);
    }

    private static function createUniqueField(string $fieldName): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($fieldName)
            ->label(__('user::user.form.label.' . $fieldName))
            ->placeholder(__('user::user.form.placeholder.' . $fieldName))
            ->required()
            ->maxLength(255)
            ->live()
            ->rules(function ($record) use ($fieldName) {
                $userId = $record?->id;
                return $userId
                    ? ["unique:users,$fieldName," . $userId]
                    : ["unique:users,$fieldName"];
            });
    }

    private static function createRolesTab(): Forms\Components\Tabs\Tab
    {
        return Forms\Components\Tabs\Tab::make('Phân quyền')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Select::make('roles')
                    ->label(__('user::user.form.label.roles'))
                    ->hiddenLabel()
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->optionsLimit(5)
                    ->columnSpanFull(),
            ]);
    }

    public static function doResendEmailVerification($settings = null, $user): void
    {
        if (!method_exists($user, 'notify')) {
            $userClass = $user::class;

            throw new Exception("Model [{$userClass}] does not have a [notify()] method.");
        }

        if ($settings->isMailSettingsConfigured()) {
            $notification = new VerifyEmail();
            $notification->url = Filament::getVerifyEmailUrl($user);

            $settings->loadMailSettingsToConfig();

            $user->notify($notification);


            Notification::make()
                ->title(__('user::user.notifications.verify_sent.title'))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('user::user.notifications.verify_warning.title'))
                ->body(__('user::user.notifications.verify_warning.description'))
                ->warning()
                ->send();
        }
    }
}
