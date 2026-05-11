<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Filament\Resources\PromotionResource\Pages;

use Modules\Promotion\App\Filament\Resources\PromotionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;
}