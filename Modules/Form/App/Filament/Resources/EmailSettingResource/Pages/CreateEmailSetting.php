<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\EmailSettingResource\Pages;

use Modules\Form\App\Filament\Resources\EmailSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailSetting extends CreateRecord
{
    protected static string $resource = EmailSettingResource::class;
}