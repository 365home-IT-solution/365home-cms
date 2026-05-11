<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources\PageResource\Pages;

use Modules\Page\App\Filament\Resources\PageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Page\Traits\HandlePage;

class EditPage extends EditRecord
{
    use HandlePage;

    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->fill($data);
    }

    protected function afterSave(): void
    {
        $this->createPage();
    }
}
