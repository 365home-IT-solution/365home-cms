<?php

namespace Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPlanResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPlanResource;
use Filament\Actions\CreateAction;

class ListMinihouseSubscriptionPlans extends ListRecords
{
    protected static string $resource = MinihouseSubscriptionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
