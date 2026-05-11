<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources\PromotionResource\Pages;

use Modules\Promotion\App\Filament\Resources\PromotionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}