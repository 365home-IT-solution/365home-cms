<?php

namespace Modules\Minihouse\App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource\Forms\PartnerForm;
use Filament\Actions;
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
        return [Actions\DeleteAction::make()];
    }
}
