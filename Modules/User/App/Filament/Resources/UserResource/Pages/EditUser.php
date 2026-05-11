<?php

declare(strict_types=1);

namespace Modules\User\App\Filament\Resources\UserResource\Pages;

use Modules\User\App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Forms;
use Filament\Support;
use Filament\Support\Enums\Alignment;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use JoseEspinal\RecordNavigation\Traits\HasRecordNavigation;

class EditUser extends EditRecord
{
    use HasRecordNavigation;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\ActionGroup::make([
                Actions\EditAction::make()
                    ->label(__('user::user.form.more_action.change_password'))
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label(__('user::user.form.action.label.password'))
                            ->password()
                            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
                            ->dehydrated(fn(?string $state): bool => filled($state))
                            ->revealable()
                            ->required(),
                        Forms\Components\TextInput::make('passwordConfirmation')
                            ->label(__('user::user.form.action.label.confirm_password'))
                            ->password()
                            ->dehydrateStateUsing(fn(string $state): string => Hash::make($state))
                            ->dehydrated(fn(?string $state): bool => filled($state))
                            ->revealable()
                            ->same('password')
                            ->required(),
                    ])
                    ->modalWidth(Support\Enums\MaxWidth::Medium)
                    ->modalHeading(__('user::user.form.action.update_password'))
                    ->modalDescription(fn($record) => $record->email)
                    ->modalAlignment(Alignment::Center)
                    ->modalCloseButton(false)
                    ->modalSubmitActionLabel(__('user::user.form.action.submit'))
                    ->modalCancelActionLabel(__('user::user.form.action.cancel')),

                Actions\DeleteAction::make()
                    ->extraAttributes(["class" => "border-b"]),

                Actions\CreateAction::make()
                    ->label(__('user::user.form.more_action.create'))
                    ->url(fn(): string => static::$resource::getNavigationUrl() . '/create'),
            ])
                ->icon('heroicon-m-ellipsis-horizontal')
                ->hiddenLabel()
                ->button()
                ->tooltip(__('user::user.form.more_action.more_action'))
                ->color('gray')
        ];

        return array_merge($this->getNavigationActions(), $actions);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string|Htmlable
    {
        $title = $this->record->name;
        $badge = $this->getBadgeStatus();

        return new HtmlString("
            <div class='flex items-center space-x-2'>
                <div>$title</div>
                $badge
            </div>
        ");
    }

    public function getBadgeStatus(): string|Htmlable
    {
        if (empty($this->record->email_verified_at)) {
            $badge = "<span class='inline-flex items-center px-2 py-1 text-xs font-semibold rounded-md text-danger-700 bg-danger-50 ring-1 ring-inset ring-danger-600/20'>Unverified</span>";
        } else {
            $badge = "<span class='inline-flex items-center px-2 py-1 text-xs font-semibold rounded-md text-success-700 bg-success-50 ring-1 ring-inset ring-success-600/20'>Verified</span>";
        }

        return $badge;
    }
}
