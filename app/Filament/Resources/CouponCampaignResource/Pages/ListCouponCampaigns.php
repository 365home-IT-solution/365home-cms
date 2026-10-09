<?php

declare(strict_types=1);

namespace App\Filament\Resources\CouponCampaignResource\Pages;

use App\Filament\Resources\CouponCampaignResource;
use Filament\Resources\Pages\ListRecords;

class ListCouponCampaigns extends ListRecords
{
    protected static string $resource = CouponCampaignResource::class;
}
