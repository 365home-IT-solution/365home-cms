<?php

namespace Modules\Minihouse\App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Services\PartnerOnboardingService;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\PartnerResource;

class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    protected array $branchIds = [];

    protected array $userIds = [];

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->branchIds = $data['branch_ids'] ?? [];
        $this->userIds = $data['user_ids'] ?? [];
        unset($data['branch_ids'], $data['user_ids'], $data['partner_type']);
        if (isset($data['legal_name'])) {
            $data['name'] = $data['legal_name'];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        PartnerForm::syncAssignments($this->record, $this->branchIds, $this->userIds);
    }

    protected function getHeaderActions(): array
    {
        $onboarding = app(PartnerOnboardingService::class);
        $pending = fn (): bool => (auth()->user()?->isSuperAdmin() ?? false) && $onboarding->awaitingSignupApproval($this->record);

        return [
            Actions\Action::make('approveSignup')
                ->label('Duyệt đăng ký & tặng dùng thử')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible($pending)
                ->requiresConfirmation()
                ->modalHeading('Duyệt đăng ký MiniHouse')
                ->modalDescription('Tặng dùng thử, kích hoạt đối tác, tạo tài khoản đăng nhập và gửi email tài khoản + mật khẩu cho đối tác.')
                ->action(function () use ($onboarding): void {
                    try {
                        $result = $onboarding->approveSignup($this->record, auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title('Không duyệt được')->body(collect($e->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    $result['created']
                        ? Notification::make()->title($result['mail_sent'] ? 'Đã duyệt và gửi tài khoản cho đối tác' : 'Đã duyệt, chưa gửi được email')->{$result['mail_sent'] ? 'success' : 'warning'}()->send()
                        : Notification::make()->title('Không tạo được tài khoản')->body($result['reason'])->danger()->send();
                    $this->refreshFormData(['status', 'verification_status']);
                }),
            Actions\Action::make('rejectSignup')
                ->label('Từ chối đăng ký')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible($pending)
                ->form([Textarea::make('reason')->label('Lý do (gửi cho đối tác)')->required()->maxLength(2000)])
                ->action(function (array $data) use ($onboarding): void {
                    $onboarding->rejectSignup($this->record, $data['reason'], auth()->user());
                    Notification::make()->title('Đã từ chối đăng ký')->success()->send();
                    $this->refreshFormData(['status', 'verification_status']);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
