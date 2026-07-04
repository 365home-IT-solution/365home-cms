<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipTierResource\Pages;

use App\Filament\Resources\MembershipTierResource;
use App\Services\MembershipService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMembershipTier extends EditRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Xoá'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        app(MembershipService::class)->distributeTierCoupons($this->record);
    }
}
