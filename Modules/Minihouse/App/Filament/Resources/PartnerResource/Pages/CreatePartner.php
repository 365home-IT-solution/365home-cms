<?php

namespace Modules\Minihouse\App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use App\Models\Partner;
use Filament\Resources\Pages\CreateRecord;
use Modules\Minihouse\App\Filament\Resources\PartnerResource;

class CreatePartner extends CreateRecord
{
    protected static string $resource = PartnerResource::class;

    protected array $branchIds = [];

    protected array $userIds = [];

    protected ?int $planId = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->branchIds = $data['branch_ids'] ?? [];
        $this->userIds = $data['user_ids'] ?? [];
        $this->planId = $data['trial_plan_id'] ?? null;
        unset($data['branch_ids'], $data['user_ids'], $data['trial_plan_id']);
        $data['partner_type'] = Partner::TYPE_MINIHOUSE;
        $data['name'] = $data['legal_name'];
        $data['created_by'] = auth()->id();
        $data['verification_status'] ??= 'pending';
        $data['contract_status'] ??= 'draft';

        return $data;
    }

    protected function afterCreate(): void
    {
        PartnerForm::syncAssignments($this->record, $this->branchIds, $this->userIds);
        PartnerForm::applyTrialPlan($this->record, $this->planId);
    }
}
