<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Resources\TtlockAccountResource\Pages;

use Modules\TTLock\App\Filament\Resources\TtlockAccountResource;
use Filament\Resources\Pages\CreateRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Category\Entities\Category;

class CreateTtlockAccount extends CreateRecord
{
    protected static string $resource = TtlockAccountResource::class;

    // 'categories' là Select ->relationship()->multiple() — pivot được Filament tự đồng bộ ở
    // saveRelationships(), sau khi record đã tồn tại, nên không có Eloquent event nào bắn ra để
    // ghi log. Ghi 1 dòng tóm tắt chi nhánh được gán ngay từ lúc tạo.
    protected function afterCreate(): void
    {
        $record      = $this->record->fresh(['categories']);
        $categoryIds = $record->categories->pluck('id')->all();

        if (empty($categoryIds)) {
            return;
        }

        AuditLogger::log(
            action: 'update',
            module: 'TtlockAccount',
            record: $record,
            old: [],
            new: ['chi_nhanh_da_them' => Category::whereIn('id', $categoryIds)->pluck('name')->implode(', ')],
            label: ($record->name ?? '#' . $record->id) . ' — Gán chi nhánh',
        );
    }
}
