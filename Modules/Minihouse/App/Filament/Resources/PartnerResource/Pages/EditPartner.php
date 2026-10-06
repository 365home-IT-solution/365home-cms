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
                ->modalDescription('Tặng dùng thử, kích hoạt đối tác, tạo tài khoản đăng nhập và gửi email tài khoản + mật khẩu cho đối tác. Đăng ký có bước giấy tờ: chỉ duyệt được khi đủ giấy tờ bắt buộc ở bảng Hồ sơ pháp lý đã được duyệt.')
                ->action(function () use ($onboarding): void {
                    try {
                        $result = $onboarding->approveSignup($this->record, auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title('Không duyệt được')->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))->danger()->persistent()->send();

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
            // Đối tác đã được kích hoạt gói (dùng thử hoặc trả tiền) mà chưa nhận được / làm mất email tài khoản: tạo tài khoản (nếu chưa có)
            // hoặc đặt mật khẩu mới rồi gửi lại email đăng nhập. Cùng điều kiện với API POST /api/admin/minihouse/partners/{partner}/resend-credentials.
            Actions\Action::make('resendCredentials')
                ->label('Gửi lại tài khoản đăng nhập')
                ->icon('heroicon-o-envelope')
                ->color('info')
                ->visible(fn (): bool => (auth()->user()?->isSuperAdmin() ?? false) && ! $this->record->isSystemPartner()
                    && filled($this->record->onboarding_token) && $this->record->subscription?->expires_at !== null)
                ->requiresConfirmation()
                ->modalHeading('Gửi lại tài khoản đăng nhập')
                ->modalDescription(fn (): string => 'Tạo tài khoản (nếu chưa có) hoặc đặt MẬT KHẨU MỚI cho tài khoản chủ đối tác, rồi gửi email thông tin đăng nhập tới ' . ($this->record->email ?: 'email của đối tác') . '. Mật khẩu cũ sẽ không dùng được nữa.')
                ->action(function () use ($onboarding): void {
                    // Kết quả gửi (thành công / gửi email thất bại) đã được resendCredentials báo bằng thông báo; ở đây chỉ báo khi không tạo được tài khoản.
                    $result = $onboarding->resendCredentials($this->record);
                    if (! $result['created'] && ! $result['mail_sent'] && filled($result['reason'] ?? null)) {
                        Notification::make()->title('Không tạo được tài khoản')->body($result['reason'])->danger()->persistent()->send();
                    }
                }),
            // Tạm dừng / mở lại hồ sơ — cùng quy tắc với API POST /api/admin/minihouse/partners/{partner}/suspend|reactivate.
            Actions\Action::make('suspend')
                ->label('Tạm dừng hồ sơ')
                ->icon('heroicon-o-pause-circle')
                ->color('gray')
                ->visible(fn (): bool => (auth()->user()?->isSuperAdmin() ?? false) && ! $this->record->isSystemPartner()
                    && $this->record->verification_status === 'approved')
                ->form([Textarea::make('note')->label('Ghi chú (không bắt buộc)')->maxLength(500)])
                ->modalDescription('Đối tác bị khoá: tài khoản của đối tác không đăng nhập được cho tới khi mở lại hồ sơ.')
                ->action(function (array $data): void {
                    app(\App\Services\PartnerLegalDocumentService::class)->suspend($this->record, auth()->user(), $data['note'] ?? null);
                    $this->record->refresh();
                    $this->refreshFormData(['status', 'verification_status']);
                    Notification::make()->title('Đã tạm dừng hồ sơ')->success()->send();
                }),
            Actions\Action::make('reactivate')
                ->label('Mở lại hồ sơ')
                ->icon('heroicon-o-play-circle')
                ->color('success')
                ->visible(fn (): bool => (auth()->user()?->isSuperAdmin() ?? false) && $this->record->verification_status === 'suspended')
                ->requiresConfirmation()
                ->modalDescription('Hồ sơ trở về trạng thái ngay trước khi tạm dừng; nếu trước đó đã được duyệt thì tài khoản của đối tác đăng nhập lại được.')
                ->action(function (): void {
                    $to = app(\App\Services\PartnerLegalDocumentService::class)->reactivate($this->record, auth()->user());
                    $this->record->refresh();
                    $this->refreshFormData(['status', 'verification_status']);
                    Notification::make()->title('Đã mở lại hồ sơ')->body($to === 'approved' ? 'Hồ sơ trở về trạng thái đã duyệt.' : 'Hồ sơ trở về trạng thái chờ duyệt.')->success()->send();
                }),
            Actions\DeleteAction::make()->before(function (Actions\DeleteAction $action) {
                if ($reason = $this->record->deletionBlockedReason()) {
                    Notification::make()->title('Không xoá được đối tác')->body($reason)->danger()->persistent()->send();
                    $action->cancel();
                }
            }),
        ];
    }
}
