<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use App\Models\Partner;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Str;
use Modules\Warehouse\App\Models\WarehouseItem;

// Danh sách vật tư gom theo "category" để hiển thị dạng optgroup (menu theo nhóm) trong Select —
// Filament Select hỗ trợ sẵn cấu trúc mảng lồng ['Nhóm' => [id => label]] để render optgroup,
// đồng thời VẪN giữ nguyên ->searchable() hoạt động bình thường. Dùng chung cho Vật tư ở cả 3 form
// Phiếu nhập / Phiếu xuất / Phiếu kiểm kê, tránh lặp lại cùng 1 query ở 3 nơi.
class WarehouseItemOptions
{
    // Tiền tố tên field cho mỗi cột CheckboxList trong pickerFormSchema() — dùng lại ở
    // pickerSelectedIds() để lọc đúng các field này ra khỏi $data khi submit modal "Thêm vật tư".
    private const PICKER_FIELD_PREFIX = 'warehouse_item_ids__';

    public static function grouped(): array
    {
        return static::baseQuery()
            ->get()
            ->groupBy(fn (WarehouseItem $item) => $item->category?->name ?: 'Chưa phân nhóm')
            ->sortKeys()
            ->map(fn ($items) => $items->pluck('name', 'id'))
            ->toArray();
    }

    // Menu "danh sách lớn" chọn vật tư — mỗi NHÓM (category) là 1 CheckboxList riêng, xếp thành
    // lưới cột (giống layout "mỗi cột 1 nhóm" đã có trước đây), dùng để nhét vào ->form() của
    // Repeater::addAction() (modal popup giữa màn hình — xem WarehouseStockIn/OutForm). 100% field
    // Filament thật (CheckboxList), KHÔNG có Alpine/dropdown tự vẽ nào — modal định vị bởi chính
    // Filament nên không còn bug lệch/che vị trí như bản dropdown tự tính toán trước đó. Vật tư ĐÃ
    // CÓ SẴN trong Repeater (truyền qua $component) bị loại khỏi danh sách chọn — tự nhiên tránh
    // chọn trùng, không cần tự vẽ trạng thái "disabled".
    public static function pickerFormSchema(Repeater $component): array
    {
        $existingIds = collect($component->getState() ?? [])
            ->pluck('warehouse_item_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        $columns = collect(static::grouped())
            ->map(fn (array $options) => collect($options)
                ->reject(fn ($label, $id) => in_array((string) $id, $existingIds, true))
                ->all())
            ->filter();

        return [
            Grid::make(['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => $columns->count() > 6 ? 4 : $columns->count()])
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

    // Gom lại danh sách warehouse_item_id đã chọn từ TOÀN BỘ các CheckboxList (mỗi nhóm 1 field
    // riêng) sau khi submit modal — dùng trong ->action() của Repeater::addAction().
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

    private static function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return WarehouseItem::query()
            ->with(['category:id,name', 'unit:id,name'])
            ->where('status', true)
            ->when(static::resolvePartnerId(), fn ($query, $partnerId) => $query->where('partner_id', $partnerId))
            ->orderBy('name');
    }

    // BelongsToPartner (global scope trên WarehouseItem) không lọc gì cho super_admin — nhìn thấy
    // TOÀN BỘ vật tư của MỌI đối tác cùng lúc. Vì các đối tác hiện seed cùng 1 bộ tên vật tư
    // (WarehouseItemSeeder), super_admin sẽ thấy mỗi vật tư lặp lại 1 lần/đối tác trong menu chọn —
    // rất khó hiểu (không phải trùng dữ liệu, mà là 2 bản ghi khác đối tác cùng tên). Để menu chọn
    // vật tư luôn ra đúng 1 danh sách rõ ràng, super_admin mặc định chỉ xem vật tư của đối tác
    // "365Home" — nhân viên/đối tác thường vẫn thấy đúng vật tư của MÌNH như bình thường (đã lọc
    // sẵn qua global scope, không cần can thiệp gì thêm ở đây).
    private static function resolvePartnerId(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if (! $user->isSuperAdmin()) {
            return null;
        }

        return Partner::where('name', '365Home')->value('id');
    }
}
