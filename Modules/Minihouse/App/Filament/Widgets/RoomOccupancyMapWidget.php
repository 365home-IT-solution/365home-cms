<?php

namespace Modules\Minihouse\App\Filament\Widgets;

use Filament\Widgets\Widget;
use Modules\Minihouse\App\Filament\Resources\ContractResource;
use Modules\Minihouse\App\Filament\Resources\RoomResource;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;

// Sơ đồ phòng THẬT theo mặt bằng — mỗi phòng vẽ đúng ô (hàng/cột) đã khai báo ở Room.position_row/
// position_col (xem RoomForm), không xếp theo thứ tự mã phòng như 1 danh sách thường. Ô nào không có
// phòng thì để trống — giống bản vẽ mặt bằng thật (dãy phòng, hành lang) thay vì lưới màu chung
// chung khó đối chiếu ngoài thực tế. Phòng CHƯA khai báo vị trí vẫn hiện đầy đủ ở khu riêng bên dưới
// mỗi tầng, không bị mất khỏi sơ đồ. Room/Contract/Invoice::query() tự áp global scope
// ActiveBuildingScope như mọi nơi khác trong panel — Widget KHÔNG cần tự lọc lại theo toà nhà.
class RoomOccupancyMapWidget extends Widget
{
    protected static string $view = 'minihouse::filament.widgets.room-occupancy-map';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = -2;

    // Widget khác trên Dashboard đều lazy-load mặc định (chỉ tải khi cuộn tới) — riêng widget này
    // tắt lazy-load: render() có gọi component Blade x-filament::dropdown (teleport ra <body>) cho
    // từng phòng trống/đang sửa, và luồng lazy-load (mount rỗng -> gọi lại render() qua request
    // Livewire riêng) khiến các component đó không lên đúng lúc, cả widget hiện trống trơn không
    // báo lỗi gì. Tắt lazy để render() chạy ngay trong request đầu, không qua vòng lazy này nữa.
    protected static bool $isLazy = false;

    public function getViewData(): array
    {
        $rooms = Room::query()
            ->with('building')
            ->orderBy('building_id')
            ->orderByRaw('floor IS NULL, floor')
            ->orderBy('code')
            ->get();

        // Hợp đồng "Đang hiệu lực" hiện tại của MỌI phòng — tra 1 lần theo room_id thay vì query lại
        // cho từng phòng trong vòng lặp bên dưới (tránh N+1 khi toà nhà có nhiều chục phòng).
        $activeContracts = Contract::query()
            ->where('status', Contract::STATUS_ACTIVE)
            ->with('tenant')
            ->get()
            ->keyBy('room_id');

        // Tổng nợ (total_amount - amount_paid) theo TỪNG hợp đồng đang hiệu lực — tính 1 lần bằng SQL
        // thay vì gọi remainingAmount() lặp qua từng Invoice (nặng nếu nhiều hoá đơn).
        $debtByContract = Invoice::query()
            ->whereIn('contract_id', $activeContracts->pluck('id'))
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->selectRaw('contract_id, SUM(total_amount - amount_paid) as debt')
            ->groupBy('contract_id')
            ->pluck('debt', 'contract_id');

        $buildings = $rooms->groupBy('building_id')->map(function ($roomsInBuilding) use ($activeContracts, $debtByContract) {
            $roomCards = $roomsInBuilding->map(function (Room $room) use ($activeContracts, $debtByContract) {
                $contract = $activeContracts->get($room->id);
                $debt     = $contract ? (float) ($debtByContract[$contract->id] ?? 0) : 0;

                // Đang có khách -> bấm là ra thẳng Sửa hợp đồng đó luôn (xem hạn thuê/hoá đơn/nợ),
                // không cần hỏi thêm gì. Trống/đang sửa thì KHÔNG đi thẳng 1 trang cố định nữa — mở
                // menu chọn hành động (Đặt phòng, Khoá/Mở khoá phòng, Sửa thông tin phòng...) vì có
                // nhiều lý do khác nhau để bấm vào 1 phòng chưa có khách — xem room-card.blade.php.
                return [
                    'id'            => $room->id,
                    'code'          => $room->code,
                    'status'        => $room->status,
                    'tenant'        => $contract?->tenant?->fullname,
                    'debt'          => $debt,
                    'contractHref'  => $contract ? ContractResource::getUrl('edit', ['record' => $contract->id]) : null,
                    'createHref'    => ContractResource::getUrl('create', ['room_id' => $room->id]),
                    'roomEditHref'  => RoomResource::getUrl('edit', ['record' => $room->id]),
                    'floor'         => $room->floor,
                    'row'           => $room->position_row,
                    'col'           => $room->position_col,
                ];
            });

            $floors = $roomCards->groupBy(fn ($room) => $room['floor'] ?? 0)
                ->map(function ($roomsOnFloor) {
                    $positioned   = $roomsOnFloor->filter(fn ($r) => filled($r['row']) && filled($r['col']))->values();
                    $unpositioned = $roomsOnFloor->reject(fn ($r) => filled($r['row']) && filled($r['col']))->values();

                    $maxRow = (int) $positioned->max('row');
                    $maxCol = (int) $positioned->max('col');

                    // Ma trận [hàng][cột] = phòng hoặc null — view chỉ việc lặp qua đúng số hàng/cột
                    // này, ô null render thành khoảng trống (không phải bị thiếu dữ liệu).
                    $grid = [];
                    for ($r = 1; $r <= $maxRow; $r++) {
                        for ($c = 1; $c <= $maxCol; $c++) {
                            $grid[$r][$c] = $positioned->first(fn ($room) => (int) $room['row'] === $r && (int) $room['col'] === $c);
                        }
                    }

                    return [
                        'grid'         => $grid,
                        'maxCol'       => $maxCol,
                        'unpositioned' => $unpositioned,
                    ];
                })
                ->sortKeys();

            // Map phòng chưa có khách (id => code/status/link) để phía Alpine tra cứu khi hiện
            // menu hành động ở HEADER của card toà nhà (không phải menu nổi trên từng ô nữa) — xem
            // room-occupancy-map.blade.php. Phòng đang thuê không cho chọn nên không cần có ở đây.
            $selectableRooms = $roomCards->reject(fn ($r) => $r['status'] === Room::STATUS_RENTED)
                ->keyBy('id')
                ->map(fn ($r) => [
                    'code'         => $r['code'],
                    'status'       => $r['status'],
                    'createHref'   => $r['createHref'],
                    'roomEditHref' => $r['roomEditHref'],
                ]);

            return [
                'building'        => $roomsInBuilding->first()->building,
                'floors'          => $floors,
                'selectableRooms' => $selectableRooms,
                'stats'           => [
                    'total'   => $roomCards->count(),
                    'empty'   => $roomCards->where('status', Room::STATUS_EMPTY)->count(),
                    'rented'  => $roomCards->where('status', Room::STATUS_RENTED)->count(),
                    'repair'  => $roomCards->where('status', Room::STATUS_REPAIR)->count(),
                    'inDebt'  => $roomCards->where('debt', '>', 0)->count(),
                    'debtSum' => $roomCards->sum('debt'),
                ],
            ];
        })->values();

        return ['buildings' => $buildings];
    }

    // Khoá/Mở khoá nhanh 1 phòng ngay từ menu trên sơ đồ — đổi qua lại Trống <-> Đã khoá, không
    // cần mở hẳn trang Sửa phòng chỉ để đổi 1 trường. Không đụng phòng đang có khách ở (đang thuê
    // luôn đi qua đúng hợp đồng, không có menu này — xem room-card.blade.php).
    public function toggleRoomRepair(int $roomId): void
    {
        $room = Room::find($roomId);

        if (! $room || $room->status === Room::STATUS_RENTED) {
            return;
        }

        $room->update([
            'status' => $room->status === Room::STATUS_REPAIR ? Room::STATUS_EMPTY : Room::STATUS_REPAIR,
        ]);
    }

    // "Chọn nhiều" ở header card toà nhà — khoá HÀNG LOẠT phòng đã chọn thành "Đã khoá" trong 1
    // lần bấm (VD sửa cả dãy điện của 1 tầng). Chỉ 1 chiều (-> Đã khoá), không có chiều ngược lại
    // hàng loạt — mở khoá từng phòng vẫn phải chọn riêng lẻ (đúng ý muốn "chỉ 1 nút" ở chế độ này).
    // Bỏ qua phòng đang có khách ở — không chọn được phòng đó từ đầu nhưng vẫn phòng thủ ở đây
    // phòng trường hợp trạng thái vừa đổi giữa lúc đang chọn.
    public function bulkMarkRepair(array $roomIds): void
    {
        Room::whereIn('id', $roomIds)
            ->where('status', '!=', Room::STATUS_RENTED)
            ->update(['status' => Room::STATUS_REPAIR]);
    }
}
