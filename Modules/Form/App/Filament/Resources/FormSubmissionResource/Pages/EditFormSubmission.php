<?php

declare(strict_types=1);

namespace Modules\Form\App\Filament\Resources\FormSubmissionResource\Pages;

use Carbon\Carbon;
use Modules\Form\App\Filament\Resources\FormSubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFormSubmission extends EditRecord
{
    protected static string $resource = FormSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['viewed_by'] = auth()->id();
        $data['viewed_at'] = Carbon::now();
        return $data;
    }
}