<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormResource\Pages;

use Modules\Form\App\Filament\Resources\FormResource;
use Filament\Resources\Pages\CreateRecord;

class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;
}