<?php

namespace Modules\Product\App\Filament\Resources\AdditionServiceResource\Pages;

use App\Services\BranchServiceSync;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\Product\App\Filament\Resources\AdditionServiceResource;

class EditAdditionService extends EditRecord
{
    protected static string $resource = AdditionServiceResource::class;

    protected array $oldBranchIds = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->oldBranchIds = $this->record->branches()->pluck('categories.id')->all();
    }

    // Đồng bộ cả chi nhánh cũ (bị bỏ → phòng mất dịch vụ) lẫn chi nhánh mới (phòng nhận dịch vụ).
    protected function afterSave(): void
    {
        app(BranchServiceSync::class)->syncBranches(array_merge(
            $this->oldBranchIds,
            $this->record->branches()->pluck('categories.id')->all()
        ));
    }
}
