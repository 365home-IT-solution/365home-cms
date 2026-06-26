<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Filament\Resources\BannerResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\AppPage\App\Filament\Resources\BannerResource;

class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;
}
