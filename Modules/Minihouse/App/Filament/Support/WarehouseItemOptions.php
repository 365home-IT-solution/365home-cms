<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Support;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Models\WarehouseItem;

// Mirror Modules\Warehouse\App\Filament\Support\WarehouseItemOptions (Home) — đổi lọc theo đối tác/
// chi nhánh thành lọc theo building_id (Toà nhà) đang chọn trên phiếu.
class WarehouseItemOptions
{
    private const PICKER_FIELD_PREFIX = 'warehouse_item_ids__';

    public static function grouped(int|string|null $buildingId = null): array
    {
        return static::baseQuery($buildingId)
            ->get()
            ->groupBy(fn (WarehouseItem $item) => $item->category?->name ?: 'Chưa phân nhóm')
            ->sortKeys()
            ->map(fn ($items) => $items->pluck('name', 'id'))
            ->toArray();
    }

    // Mỗi NHÓM vật tư là 1 CheckboxList riêng xếp thành lưới cột, dùng trong ->form() của
    // Repeater::addAction() (modal chọn nhiều vật tư 1 lúc). Vật tư ĐÃ CÓ trong Repeater bị loại khỏi
    // danh sách. Get/Set trong modal KHÔNG thấy field form ngoài nên đọc thẳng
    // $component->getLivewire()->data để biết building_id đang chọn trên phiếu.
    public static function pickerFormSchema(Repeater $component): array
    {
        $existingIds = collect($component->getState() ?? [])
            ->pluck('warehouse_item_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $buildingId = ($component->getLivewire()->data ?? [])['building_id'] ?? null;

        $columns = collect(static::grouped($buildingId))
            ->map(fn (array $options) => collect($options)
                ->reject(fn ($label, $id) => in_array((string) $id, $existingIds, true))
                ->all())
            ->filter();

        return [
            Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => $columns->count() > 6 ? 4 : max(1, $columns->count())])
                ->schema($columns->map(fn (array $options, string $category) => CheckboxList::make(
                    static::PICKER_FIELD_PREFIX . Str::slug($category)
                )
                    ->label($category)
                    ->options($options)
                    ->columns(1)
                    ->bulkToggleable())
                    ->values()
                    ->all()),
        ];
    }

    public static function pickerSelectedIds(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => str_starts_with($key, self::PICKER_FIELD_PREFIX))
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function baseQuery(int|string|null $buildingId): Builder
    {
        return WarehouseItem::query()
            ->with(['category:id,name', 'unit:id,name'])
            ->where('status', true)
            ->when($buildingId, fn (Builder $query, $bid) => $query->where('building_id', $bid))
            ->orderBy('name');
    }
}
