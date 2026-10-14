<?php

namespace Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

use App\Services\BranchServiceSync;
use Filament\Resources\Pages\CreateRecord;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;

class CreateAdditionService extends CreateRecord
{
    protected static string $resource = AdditionServiceResource::class;

    // Ô "Chi nhánh áp dụng" được Filament sync pivot SAU khi tạo bản ghi — đồng bộ phòng ở đây.
    protected function afterCreate(): void
    {
        app(BranchServiceSync::class)->syncBranches($this->record->branches()->pluck('categories.id')->all());
    }
}
