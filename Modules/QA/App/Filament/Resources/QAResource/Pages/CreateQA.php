<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources\QAResource\Pages;

use Modules\QA\App\Filament\Resources\QAResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQA extends CreateRecord
{
    protected static string $resource = QAResource::class;
}