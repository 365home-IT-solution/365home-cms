<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Metering\App\Exceptions\CannotDeleteUsedMeteringReadingException;
use Modules\Metering\App\Models\MeteringReading;
use Modules\Minihouse\App\Models\Room;

// API cho module Metering (tách riêng khỏi Minihouse — xem Modules/Metering) — CHỈ quản lý CHỈ SỐ
// điện/nước theo phòng/tháng, KHÔNG quản lý đơn giá (đơn giá vẫn ở Building/Contract, xem
// InvoiceController). Dùng chung permission group 'rooms' với RoomController — đúng convention đã
// áp dụng cho Filament Resource (MeteringReadingResource::permissionGroup() = 'rooms'), không tạo bộ
// quyền riêng.
//
// electric_start/water_start KHÔNG nhận qua request — model tự tính lúc tạo (xem
// MeteringReading::booted()), giữ đúng nguyên tắc "không cho nhập tay số đầu kỳ" đã áp dụng ở
// Filament (MeteringReadingForm).
class MeteringReadingController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/metering-readings?room_id=&month=YYYY-MM&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_rooms')) {
            return response()->json(['message' => 'Không có quyền xem Số điện nước.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        $readings = MeteringReading::query()
            ->withoutGlobalScopes()
            ->with('room:id,name,building_id')
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', $permitted))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->input('room_id')))
            ->when($request->filled('month'), fn ($q) => $q->whereDate('month', Carbon::parse($request->string('month'))->startOfMonth()))
            ->orderByDesc('month')
            ->paginate((int) $request->integer('per_page', 20));

        $readings->getCollection()->transform(fn (MeteringReading $r) => $this->toListItem($r));

        return response()->json($readings);
    }

    // GET /api/admin/minihouse/metering-readings/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_rooms')) {
            return response()->json(['message' => 'Không có quyền xem Số điện nước.'], 403);
        }

        $reading = MeteringReading::withoutGlobalScopes()->with('room:id,name,building_id')->find($id);

        if (! $reading || ! $this->isBuildingAllowed($request, $reading->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy log Số điện nước.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($reading)]);
    }

    // POST /api/admin/minihouse/metering-readings
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_rooms')) {
            return response()->json(['message' => 'Không có quyền tạo Số điện nước.'], 403);
        }

        $data = $request->validate([
            'room_id'      => 'required|string|exists:products,id',
            'month'        => 'required|date',
            'electric_end' => 'required|numeric|min:0',
            'water_end'    => 'required|numeric|min:0',
            'note'         => 'nullable|string',
        ]);

        $room = Room::withoutGlobalScope('activeBuilding')->find($data['room_id']);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không có quyền tạo Số điện nước cho phòng này.'], 403);
        }

        $month = Carbon::parse($data['month'])->startOfMonth();

        if (MeteringReading::where('room_id', $room->id)->where('month', $month->toDateString())->exists()) {
            return response()->json(['message' => 'Phòng này đã có log Số điện nước cho đúng tháng ' . $month->format('m/Y') . ' rồi.'], 422);
        }

        // electric_start/water_start KHÔNG nhận từ $data — model tự tính (xem lời dẫn ở đầu file).
        $reading = MeteringReading::create([
            'room_id'      => $room->id,
            'month'        => $month,
            'electric_end' => $data['electric_end'],
            'water_end'    => $data['water_end'],
            'note'         => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $this->toDetailItem($reading->fresh('room'))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/metering-readings/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_rooms')) {
            return response()->json(['message' => 'Không có quyền sửa Số điện nước.'], 403);
        }

        $reading = MeteringReading::withoutGlobalScopes()->find($id);

        if (! $reading || ! $this->isBuildingAllowed($request, $reading->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy log Số điện nước.'], 404);
        }

        // CHỈ cho sửa electric_end/water_end/note — KHÔNG cho đổi room_id/month của 1 log đã tồn tại
        // (electric_start/water_start đã tính cố định lúc tạo theo đúng phòng/tháng ban đầu; đổi
        // phòng/tháng sau đó sẽ làm lệch hẳn dây chuyền nối tiếp mà không có gì tự sửa lại) — cùng
        // giới hạn Filament MeteringReadingForm đang áp dụng trên thực tế (thay đổi phòng/tháng của
        // 1 bản ghi đang sửa không tính lại số đầu kỳ).
        $data = $request->validate([
            'electric_end' => 'sometimes|required|numeric|min:0',
            'water_end'    => 'sometimes|required|numeric|min:0',
            'note'         => 'nullable|string',
        ]);

        $reading->update($data);

        return response()->json(['data' => $this->toDetailItem($reading->fresh('room'))]);
    }

    // DELETE /api/admin/minihouse/metering-readings/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_rooms')) {
            return response()->json(['message' => 'Không có quyền xoá Số điện nước.'], 403);
        }

        $reading = MeteringReading::withoutGlobalScopes()->find($id);

        if (! $reading || ! $this->isBuildingAllowed($request, $reading->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy log Số điện nước.'], 404);
        }

        try {
            $reading->delete();
        } catch (CannotDeleteUsedMeteringReadingException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Đã xoá log Số điện nước.']);
    }

    private function toListItem(MeteringReading $reading): array
    {
        return [
            'id'             => $reading->id,
            'room_id'        => $reading->room_id,
            'room_code'      => $reading->room?->code,
            'building_id'    => $reading->room?->building_id,
            'month'          => $reading->month->format('Y-m'),
            'electric_start' => $reading->electric_start,
            'electric_end'   => $reading->electric_end,
            'water_start'    => $reading->water_start,
            'water_end'      => $reading->water_end,
        ];
    }

    private function toDetailItem(MeteringReading $reading): array
    {
        return [
            'id'                => $reading->id,
            'room_id'           => $reading->room_id,
            'room_code'         => $reading->room?->code,
            'building_id'       => $reading->room?->building_id,
            'month'             => $reading->month->format('Y-m'),
            'electric_start'    => $reading->electric_start,
            'electric_end'      => $reading->electric_end,
            'water_start'       => $reading->water_start,
            'water_end'         => $reading->water_end,
            'note'              => $reading->note,
            'is_used_in_invoice' => $reading->isUsedInInvoice(),
            'created_at'        => $reading->created_at?->toIso8601String(),
            'updated_at'        => $reading->updated_at?->toIso8601String(),
        ];
    }
}
