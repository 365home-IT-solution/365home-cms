<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormSubmissionResource\Pages;

use Modules\Form\App\Filament\Resources\FormSubmissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFormSubmission extends CreateRecord
{
    protected static string $resource = FormSubmissionResource::class;
}
