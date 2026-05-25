<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Forms;

use Filament\Forms\Form;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
                    ->relationship('roles', 'name', function ($query) {
                        $user = auth()->user();
                        $query->where('name', '!=', config('filament-shield.panel_user.name'));
                        if ($user && ! $user->isSuperAdmin()) {
                            $query->where('created_by', $user->id);
                        }
                    })
                    ->getOptionLabelFromRecordUsing(fn(Model $record) => Str::headline($record->name))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->optionsLimit(5)
                    ->columnSpanFull(),
            ]);
    }

}
