<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use App\Models\Partner;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;
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

    public static function grouped(?string $partnerId = null, int|string|null $branchId = null): array
    {
        return static::baseQuery($partnerId, $branchId)
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
    //
    // QUAN TRỌNG: Get/Set bên trong ->form() của addAction() resolve theo schema RIÊNG của modal,
    // KHÔNG thấy được field 'partner_id'/'branch_id' ở form NGOÀI (Repeater cha) — đã xác nhận thực
    // tế. Phải đọc thẳng $component->getLivewire()->data (mảng Filament đang bind form NGOÀI vào,
    // cùng kỹ thuật đã dùng ở HasRoomBranchPicker::selectStockOutRoom()) để biết ĐÚNG đối
    // tác/chi nhánh đang chọn trên phiếu, nếu không vật tư của MỌI chi nhánh (cùng đối tác) sẽ trộn
    // lẫn vào chung 1 danh sách — đã xảy ra thực tế khi nhiều chi nhánh copy chung 1 bộ tên vật tư.
    public static function pickerFormSchema(Repeater $component): array
    {
        $existingIds = collect($component->getState() ?? [])
            ->pluck('warehouse_item_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();

        [$partnerId, $branchId] = static::resolveOuterFormScope($component);

        $columns = collect(static::grouped($partnerId, $branchId))
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

    private static function baseQuery(?string $partnerId, int|string|null $branchId): Builder
    {
        // Bỏ qua global scope "branch" (BelongsToBranch) — scope đó lọc theo MỌI chi nhánh tài
        // khoản được PHÉP xem (rootProductCategoryIds()), không phải chi nhánh CỤ THỂ đang chọn
        // trên phiếu. Ở đây cần ghim đúng 1 chi nhánh (branch_id truyền vào), nếu không tài khoản
        // quản lý nhiều chi nhánh vẫn sẽ thấy lẫn vật tư của các chi nhánh khác trong cùng menu.
        // Global scope "partner" (BelongsToPartner) vẫn giữ nguyên — luôn đúng đối tác đang đăng
        // nhập, không cần ghi đè cho tài khoản thường.
        return WarehouseItem::query()
            ->withoutGlobalScope('branch')
            ->with(['category:id,name', 'unit:id,name'])
            ->where('status', true)
            ->when($partnerId, fn (Builder $query, string $pid) => $query->where('partner_id', $pid))
            ->when($branchId, fn (Builder $query, $bid) => $query->where('branch_id', $bid))
            ->orderBy('name');
    }

    // Đọc đúng partner_id/branch_id ĐANG CHỌN trên phiếu (form ngoài), không phải mặc định đoán mò.
    private static function resolveOuterFormScope(Repeater $component): array
    {
        $user = auth()->user();

        if (! $user) {
            return [null, null];
        }

        $outerData = $component->getLivewire()->data ?? [];

        if ($user->isSuperAdmin()) {
            // super_admin tự chọn Đối tác + Chi nhánh trên phiếu — đọc đúng giá trị đó. Trước khi
            // chọn đối tác, tạm mặc định về đối tác "365Home" để menu không rỗng trơn (giữ đúng
            // hành vi cũ), nhưng KHÔNG còn tự ý bỏ qua chi nhánh đã chọn như trước nữa.
            $partnerId = $outerData['partner_id'] ?? Partner::where('name', '365Home')->value('id');
            $branchId  = $outerData['branch_id'] ?? null;

            return [$partnerId, $branchId];
        }

        // Tài khoản thường: partner_id đã tự lọc qua global scope "partner", không cần truyền tay.
        // branch_id: nếu tài khoản quản lý > 1 chi nhánh thì phải lấy ĐÚNG chi nhánh đã chọn trên
        // phiếu (field 'branch_id' hiện ra trên form); nếu chỉ quản lý đúng 1 chi nhánh (field ẩn,
        // không có trong $outerData) thì dùng thẳng chi nhánh duy nhất đó.
        $branchIds = $user->rootProductCategoryIds();
        $branchId  = $outerData['branch_id'] ?? (count($branchIds) === 1 ? $branchIds[0] : null);

        return [null, $branchId];
    }
}
