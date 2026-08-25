<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Models\PartnerStatusLog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    // 'branch_ids'/'user_ids' (tab "Chi nhánh"/"Người dùng") không phải cột của bảng partners —
    // giữ lại tạm ở đây, tách khỏi $data trước khi tạo, rồi áp dụng ở afterCreate() (đã có $record).
    private array $pendingBranchIds = [];
    private array $pendingUserIds   = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingBranchIds = $data['branch_ids'] ?? [];
        $this->pendingUserIds   = $data['user_ids'] ?? [];
        unset($data['branch_ids'], $data['user_ids']);

        // Cột 'name' (bắt buộc, không có default) là tên hiển thị dùng chung ở mọi nơi khác
        // trong hệ thống (dropdown chọn đối tác, cột "Đối tác" trên bảng...) — form 7 tab không
        // có field 'name' riêng, luôn lấy từ 'legal_name' (Tên doanh nghiệp Tiếng Việt).
        $data['name']                 = $data['legal_name'] ?? null;
        $data['created_by']          = auth()->id();
        $data['verification_status'] = $data['verification_status'] ?? 'pending';
        $data['status']              = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Model $record */
        $record = $this->record;

        PartnerStatusLog::create([
            'partner_id'  => $record->id,
            'from_status' => null,
            'to_status'   => $record->verification_status,
            'note'        => 'Hồ sơ đối tác được tạo mới.',
            'changed_by'  => auth()->id(),
        ]);

        PartnerForm::syncAssignments($record, $this->pendingBranchIds, $this->pendingUserIds);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
