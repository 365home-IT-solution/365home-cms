<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipTierResource\Pages;

use App\Filament\Resources\MembershipTierResource;
use App\Services\MembershipService;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipTier extends CreateRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        app(MembershipService::class)->distributeTierCoupons($this->record);
    }
}
