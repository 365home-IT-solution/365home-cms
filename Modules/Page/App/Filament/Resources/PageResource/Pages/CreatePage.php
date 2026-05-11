<?php

declare(strict_types=1);

namespace Modules\Page\App\Filament\Resources\PageResource\Pages;

use Modules\Page\App\Filament\Resources\PageResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\Page\Traits\HandlePage;

class CreatePage extends CreateRecord
{
    use HandlePage;
    
    protected static string $resource = PageResource::class;

    protected function afterCreate(): void
    {
        $this->createPage();
    }
}
