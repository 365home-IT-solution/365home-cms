<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Models\PartnerStatusLog;
use App\Services\PartnerLegalDocumentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    // 'branch_ids'/'user_ids' (tab "Chi nhánh"/"Người dùng") không phải cột của bảng partners —
    // giữ lại tạm ở đây, tách khỏi $data trước khi lưu, rồi áp dụng ở afterSave().
    private array $pendingBranchIds = [];

    private array $pendingUserIds = [];

    public function getTitle(): string|Htmlable
    {
        return "Xác minh đối tác: {$this->record->name}";
    }

    // Giữ 'name' (tên hiển thị dùng chung toàn hệ thống) luôn đồng bộ với 'legal_name' — form
    // 7 tab không có field 'name' riêng để tự sửa.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingBranchIds = $data['branch_ids'] ?? [];
        $this->pendingUserIds = $data['user_ids'] ?? [];
        unset($data['branch_ids'], $data['user_ids']);

        if (! empty($data['legal_name'])) {
            $data['name'] = $data['legal_name'];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        PartnerForm::syncAssignments($this->record, $this->pendingBranchIds, $this->pendingUserIds);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Tạm dừng hồ sơ')
                ->color('gray')
                ->icon('heroicon-o-pause-circle')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->verification_status !== 'suspended')
                ->action(fn () => $this->changeStatus('suspended', 'Tạm dừng hồ sơ')),

            Action::make('rejectDossier')
                ->label('Từ chối hồ sơ')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->visible(fn () => $this->record->usesContract() && $this->record->verification_status !== 'rejected' && $this->record->contract_status !== 'active')
                ->modalHeading('Từ chối hồ sơ đối tác')
                ->modalDescription('Hồ sơ bị từ chối, link ký hợp đồng chưa được xác nhận sẽ mất hiệu lực. Với hồ sơ đăng ký trên website, lý do được gửi cho đối tác qua email.')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')->label('Lý do từ chối')->required()->maxLength(2000),
                ])
                ->action(function (array $data) {
                    try {
                        app(PartnerLegalDocumentService::class)->rejectDossier($this->record, auth()->user(), $data['reason']);
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()->title('Không thể từ chối hồ sơ')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();

                        return;
                    }

                    $this->record->refresh();
                    Notification::make()->title('Đã từ chối hồ sơ')->warning()->send();
                }),

            Action::make('approve')
                ->label('Phê duyệt chính thức')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->usesContract() && $this->record->verification_status !== 'approved')
                ->action(function () {
                    // Hồ sơ pháp lý chưa đủ điều kiện → service ném ValidationException(legal_documents) mà form không có ô nào hiển thị,
                    // nên trước đây bấm "Xác nhận" không thấy gì. Hiện rõ lý do thay vì im lặng.
                    try {
                        app(PartnerLegalDocumentService::class)->approveDossier($this->record, auth()->user());
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        Notification::make()
                            ->title('Chưa thể phê duyệt — hồ sơ pháp lý chưa đủ điều kiện')
                            ->body(collect($e->errors())->flatten()->map(fn ($m) => '• ' . $m)->implode("\n"))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $this->record->refresh();
                    Notification::make()->title('Đã phê duyệt hồ sơ pháp lý')->success()->send();
                }),

            Action::make('resendCredentials')
                ->label('Gửi lại tài khoản đăng nhập')
                ->color('info')
                ->icon('heroicon-o-envelope')
                ->requiresConfirmation()
                ->modalDescription('Tạo tài khoản (nếu chưa có) hoặc đặt mật khẩu mới cho tài khoản chủ đối tác, rồi gửi email xác nhận hợp tác kèm thông tin đăng nhập.')
                ->visible(fn () => $this->record->usesContract() ? ($this->record->contract_status === 'active' && filled($this->record->onboarding_token)) : (filled($this->record->onboarding_token) && $this->record->subscription?->expires_at !== null))
                ->action(function () {
                    $result = app(\App\Services\PartnerOnboardingService::class)->resendCredentials($this->record);
                    if (! $result['created'] && ! $result['mail_sent'] && filled($result['reason'] ?? null)) {
                        Notification::make()->title('Không tạo được tài khoản')->body($result['reason'])->danger()->persistent()->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }

    private function changeStatus(string $to, string $label): void
    {
        $from = $this->record->verification_status;

        $this->record->update([
            'verification_status' => $to,
            'status' => $to === 'approved',
        ]);

        PartnerStatusLog::create([
            'partner_id' => $this->record->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $label,
            'changed_by' => auth()->id(),
        ]);

        Notification::make()
            ->title("Đã cập nhật trạng thái: {$label}")
            ->success()
            ->send();
    }
}
