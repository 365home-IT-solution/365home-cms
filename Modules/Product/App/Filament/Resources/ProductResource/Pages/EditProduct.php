<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ProductResource\Pages;

use App\Models\Tag;
use App\Services\BranchServiceSync;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Modules\AuditLog\Services\AuditLogger;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Filament\Resources\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected array $oldTagIds     = [];
    protected array $oldServiceIds = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // "Tiện ích" (field 'tags') và "Dịch vụ bổ sung" (field 'additionalServices') trên form đều là
    // Select ->relationship() — Filament tự đồng bộ pivot (model_has_tags/product_addition_service)
    // ở bước saveRelationships() riêng, KHÔNG đi qua $record->update() nên ProductObserver không
    // hề thấy được thay đổi này (đã xác nhận qua thực tế: bỏ 1 tiện ích không để lại log nào — cùng
    // lỗi với RoomAmenityAssign). beforeSave() chạy TRƯỚC bước sync đó nên chụp lại state cũ ở đây,
    // afterSave() so sánh với state mới rồi ghi log thủ công.
    protected function beforeSave(): void
    {
        $this->oldTagIds     = $this->record->tags()->pluck('tags.id')->map(fn ($id) => (string) $id)->all();
        $this->oldServiceIds = $this->record->additionalServices()->pluck('additional_services.id')->map(fn ($id) => (string) $id)->all();
    }

    protected function afterSave(): void
    {
        // Dịch vụ bổ sung kế thừa theo chi nhánh — đổi chi nhánh/khu vực thì đồng bộ lại TRƯỚC khi so
        // sánh để log đúng dịch vụ được thêm/bỏ theo chi nhánh mới.
        app(BranchServiceSync::class)->syncRoom($this->record);

        $record = $this->record->fresh(['tags', 'additionalServices']);

        $newTagIds     = $record->tags->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedTagIds   = array_diff($newTagIds, $this->oldTagIds);
        $removedTagIds = array_diff($this->oldTagIds, $newTagIds);

        $newServiceIds     = $record->additionalServices->pluck('id')->map(fn ($id) => (string) $id)->all();
        $addedServiceIds   = array_diff($newServiceIds, $this->oldServiceIds);
        $removedServiceIds = array_diff($this->oldServiceIds, $newServiceIds);

        if (empty($addedTagIds) && empty($removedTagIds) && empty($addedServiceIds) && empty($removedServiceIds)) {
            return;
        }

        $locale = app()->getLocale();
        $old    = [];
        $new    = [];

        if (! empty($removedTagIds)) {
            $old['tien_ich_da_bo'] = Tag::whereIn('id', $removedTagIds)->get()
                ->map(fn ($tag) => $tag->getTranslation('name', $locale))->implode(', ');
        }
        if (! empty($addedTagIds)) {
            $new['tien_ich_da_them'] = Tag::whereIn('id', $addedTagIds)->get()
                ->map(fn ($tag) => $tag->getTranslation('name', $locale))->implode(', ');
        }
        if (! empty($removedServiceIds)) {
            $old['dich_vu_da_bo'] = AdditionService::whereIn('id', $removedServiceIds)->pluck('name')->implode(', ');
        }
        if (! empty($addedServiceIds)) {
            $new['dich_vu_da_them'] = AdditionService::whereIn('id', $addedServiceIds)->pluck('name')->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'Product',
            record: $record,
            old: $old,
            new: $new,
            label: ($record->name ?? '#' . $record->id) . ' — Cập nhật tiện ích/dịch vụ bổ sung',
        );
    }
}
