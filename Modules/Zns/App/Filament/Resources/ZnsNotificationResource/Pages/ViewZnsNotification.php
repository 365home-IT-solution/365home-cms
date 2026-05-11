<?php

declare(strict_types=1);

namespace Modules\Zns\App\Filament\Resources\ZnsNotificationResource\Pages;

use Filament\Infolists\Infolist;
use Modules\Zns\App\Filament\Resources\ZnsNotificationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewZnsNotification extends ViewRecord
{
    protected static string $resource = ZnsNotificationResource::class;

    public function getInfolist(string $name = 'default'): ?Infolist
    {
        $infolist = parent::getInfolist($name);

        return ZnsNotificationResource::infolist($infolist);
    }
}
