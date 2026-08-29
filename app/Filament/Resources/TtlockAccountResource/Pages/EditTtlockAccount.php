<?php

declare(strict_types=1);

namespace App\Filament\Resources\TtlockAccountResource\Pages;

use App\Filament\Resources\TtlockAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Category\Entities\Category;

class EditTtlockAccount extends EditRecord
{
    protected static string $resource = TtlockAccountResource::class;

    protected array $oldCategoryIds = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    // Field 'categories' là Select ->relationship()->multiple() — pivot được Filament tự đồng bộ ở
    // saveRelationships(), KHÔNG đi qua $record->update() nên không có Eloquent event nào bắn ra
    // để ghi log (cùng lỗi đã gặp ở Product tags/services). beforeSave() chạy TRƯỚC bước đó nên
    // chụp lại state cũ ở đây, afterSave() so sánh với state mới rồi ghi log thủ công.
    protected function beforeSave(): void
    {
        $this->oldCategoryIds = $this->record->categories()->pluck('categories.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        $record = $this->record->fresh(['categories']);

        $newCategoryIds = $record->categories->pluck('id')->map(fn ($id) => (string) $id)->all();
        $added          = array_diff($newCategoryIds, $this->oldCategoryIds);
        $removed        = array_diff($this->oldCategoryIds, $newCategoryIds);

        if (empty($added) && empty($removed)) {
            return;
        }

        $old = [];
        $new = [];

        if (! empty($removed)) {
            $old['chi_nhanh_da_bo'] = Category::whereIn('id', $removed)->pluck('name')->implode(', ');
        }
        if (! empty($added)) {
            $new['chi_nhanh_da_them'] = Category::whereIn('id', $added)->pluck('name')->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'TtlockAccount',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->name ?? '#' . $record->id) . ' — Cập nhật chi nhánh',
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
