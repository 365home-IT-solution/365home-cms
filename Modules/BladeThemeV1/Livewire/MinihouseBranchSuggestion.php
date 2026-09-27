<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\View\View;
use Livewire\Component;
use Modules\Minihouse\App\Models\Building;

/**
 * "Các chi nhánh MiniHouse tại {khu vực}" ở trang chủ — mirror ĐÚNG cấu trúc/hành vi của
 * BranchSuggestion.php (component "Các chi nhánh homestay tại...") ngay phía trên, chỉ đổi nguồn
 * dữ liệu sang Building (toà nhà MiniHouse, cho thuê THEO THÁNG) thay vì Category (chi nhánh
 * Homestay, đặt ngắn hạn). Cùng lý do cần đợi client gọi setProvince() qua $wire (khu vực chỉ có ở
 * localStorage, xem branch-suggestion.blade.php).
 *
 * MiniHouse KHÔNG có bảng province_branches như Home (chưa cần join theo tỉnh có cấu trúc) — khớp
 * khu vực bằng cách kiểm tra address chứa tên tỉnh (LIKE), đủ dùng vì Building.address luôn có dạng
 * "..., <Tên tỉnh/thành>" (xem seed dữ liệu mẫu). Chỉ hiện toà đang bật VÀ còn ít nhất 1 phòng
 * "Trống" — hiện toà không còn phòng nào sẽ dẫn khách vào trang trống trơn sau khi bấm vào.
 */
class MinihouseBranchSuggestion extends Component
{
    public ?int $provinceId = null;
    public string $provinceName = '';
    public array $buildings = [];

    public function setProvince($id, $name = null): void
    {
        $this->provinceId = $id ? (int) $id : null;
        $this->provinceName = (string) ($name ?? '');
        $this->loadBuildings();
    }

    protected function loadBuildings(): void
    {
        if (! $this->provinceName) {
            $this->buildings = [];

            return;
        }

        // Province thật của Home luôn có tiền tố "Thành phố "/"Tỉnh " (VD "Thành phố Cần Thơ") —
        // Building.address (MiniHouse) lại chỉ ghi tên thường (VD "..., Cần Thơ", không có tiền
        // tố), nên so khớp LIKE nguyên văn luôn trật. Bỏ 2 tiền tố phổ biến này trước khi so khớp.
        $searchName = trim(preg_replace('/^(Thành phố|Tỉnh)\s+/u', '', $this->provinceName));

        // "address" là thuộc tính ẢO uỷ quyền qua BuildingSetting (không phải cột thật trên
        // categories) — không lọc thẳng where('address', ...) được, phải qua whereHas('detail', ...)
        // đúng bảng minihouse_building_settings thật sự chứa cột này (xem Building::detail()).
        $this->buildings = Building::withoutGlobalScope('activeBuilding')
            ->where('status', true)
            ->whereHas('detail', fn ($q) => $q->where('address', 'like', '%' . $searchName . '%'))
            ->whereHas('rooms', fn ($q) => $q->available())
            ->with(['rooms' => fn ($q) => $q->available()->orderBy('price')])
            ->orderBy('name')
            ->get()
            ->map(function (Building $building) {
                $cheapest = $building->rooms->first();

                return [
                    'id'    => $building->id,
                    'name'  => $building->name,
                    'image' => $cheapest?->photos[0] ?? null,
                ];
            })
            ->values()
            ->toArray();
    }

    public function getViewAllUrlProperty(): string
    {
        return '/minihouse';
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.minihouse-branch-suggestion');
    }
}
