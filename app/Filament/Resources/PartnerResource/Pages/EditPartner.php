<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Models\PartnerStatusLog;
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
    private array $pendingUserIds   = [];

    public function getTitle(): string|Htmlable
    {
        return "Xác minh đối tác: {$this->record->name}";
    }

    // Giữ 'name' (tên hiển thị dùng chung toàn hệ thống) luôn đồng bộ với 'legal_name' — form
    // 7 tab không có field 'name' riêng để tự sửa.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingBranchIds = $data['branch_ids'] ?? [];
        $this->pendingUserIds   = $data['user_ids'] ?? [];
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

            Action::make('approve')
                ->label('Phê duyệt chính thức')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->verification_status !== 'approved')
                ->action(fn () => $this->changeStatus('approved', 'Phê duyệt chính thức')),

            DeleteAction::make(),
        ];
    }

    private function changeStatus(string $to, string $label): void
    {
        $from = $this->record->verification_status;

        $this->record->update([
            'verification_status' => $to,
            'status'               => $to === 'approved',
        ]);

        PartnerStatusLog::create([
            'partner_id'  => $this->record->id,
            'from_status' => $from,
            'to_status'   => $to,
            'note'        => $label,
            'changed_by'  => auth()->id(),
        ]);

        Notification::make()
            ->title("Đã cập nhật trạng thái: {$label}")
            ->success()
            ->send();
    }
}
