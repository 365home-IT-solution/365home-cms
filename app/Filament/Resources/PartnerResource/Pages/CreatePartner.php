<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Models\Partner;
use App\Models\PartnerStatusLog;
use App\Services\PartnerLegalDocumentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    // 'branch_ids'/'user_ids' (tab "Chi nhánh"/"Người dùng") không phải cột của bảng partners —
    // giữ lại tạm ở đây, tách khỏi $data trước khi tạo, rồi áp dụng ở afterCreate() (đã có $record).
    private array $pendingBranchIds = [];

    private array $pendingUserIds = [];

    // Gói dùng thử được chọn ở tab "Gói dịch vụ" (không phải cột của partners).
    private ?int $pendingPlanId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingBranchIds = $data['branch_ids'] ?? [];
        $this->pendingUserIds = $data['user_ids'] ?? [];
        $this->pendingPlanId = $data['trial_plan_id'] ?? null;
        unset($data['branch_ids'], $data['user_ids'], $data['trial_plan_id']);

        // Cột 'name' (bắt buộc, không có default) là tên hiển thị dùng chung ở mọi nơi khác
        // trong hệ thống (dropdown chọn đối tác, cột "Đối tác" trên bảng...) — form 7 tab không
        // có field 'name' riêng, luôn lấy từ 'legal_name' (Tên doanh nghiệp Tiếng Việt).
        $data['name'] = $data['legal_name'] ?? null;
        $data['partner_type'] = Partner::TYPE_HOMESTAY;
        $data['created_by'] = auth()->id();
        $data['verification_status'] = $data['verification_status'] ?? 'pending';
        $data['status'] = true;

        // Super Admin tạo trực tiếp → không cần bước duyệt hồ sơ/giấy tờ (đã duyệt ngay). Luồng đăng ký trên website không đi qua trang này.
        return [...$data, ...app(PartnerLegalDocumentService::class)->reviewExemptAttributes(auth()->user())];
    }

    protected function afterCreate(): void
    {
        /** @var Model $record */
        $record = $this->record;

        PartnerStatusLog::create([
            'partner_id' => $record->id,
            'from_status' => null,
            'to_status' => $record->verification_status,
            'note' => app(PartnerLegalDocumentService::class)->creationNote($record, 'ở trang quản trị'),
            'changed_by' => auth()->id(),
        ]);

        PartnerForm::syncAssignments($record, $this->pendingBranchIds, $this->pendingUserIds);

        PartnerForm::applyTrialPlan($record, $this->pendingPlanId);

        $this->attachDossierDocuments($record);
    }

    // Giấy tờ đã tải ở khối "Tải hồ sơ để tự điền" → hồ sơ pháp lý của đối tác. Hồ sơ Super Admin tạo (không cần duyệt): giấy tờ được duyệt luôn.
    private function attachDossierDocuments(Model $record): void
    {
        $files = array_filter([
            'gpkd'       => PartnerForm::firstUploadedFile($this->data['dossier_gpkd'] ?? null),
            'cccd_front' => PartnerForm::firstUploadedFile($this->data['dossier_cccd_front'] ?? null),
            'cccd_back'  => PartnerForm::firstUploadedFile($this->data['dossier_cccd_back'] ?? null),
            'antt'       => PartnerForm::firstUploadedFile($this->data['dossier_antt'] ?? null),
        ]);

        if ($files === []) {
            return;
        }

        try {
            $scans = json_decode((string) ($this->data['dossier_scans'] ?? ''), true) ?: [];
            $result = app(\App\Services\PartnerDossierScanner::class)->attach($record, $files, $scans, (string) auth()->id());
        } catch (\Throwable $exception) {
            report($exception);
            \Filament\Notifications\Notification::make()->title('Đã tạo đối tác nhưng chưa đưa được giấy tờ vào hồ sơ pháp lý — tải lại ở tab Tài liệu & Xác minh.')->warning()->send();

            return;
        }

        $approved = app(PartnerLegalDocumentService::class)->autoApproveDocuments($record, auth()->user(), array_values($result['created']));

        if ($result['created'] !== []) {
            \Filament\Notifications\Notification::make()
                ->title('Đã đưa ' . count($result['created']) . ' giấy tờ vào hồ sơ pháp lý' . ($approved > 0 ? '' : ' (chờ duyệt)'))
                ->success()->send();
        }

        foreach ($result['skipped'] as $message) {
            \Filament\Notifications\Notification::make()->title($message)->warning()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
