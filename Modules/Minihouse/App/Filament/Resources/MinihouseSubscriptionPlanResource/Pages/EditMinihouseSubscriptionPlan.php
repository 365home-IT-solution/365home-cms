<?php

namespace Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPlanResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPlanResource;
use Filament\Actions\DeleteAction;

class EditMinihouseSubscriptionPlan extends EditRecord
{
    protected static string $resource = MinihouseSubscriptionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
