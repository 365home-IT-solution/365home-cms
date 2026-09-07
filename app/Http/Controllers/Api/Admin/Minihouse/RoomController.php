<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Room;

class RoomController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/rooms?building_id=&status=&search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_rooms')) {
            return response()->json(['message' => 'Không có quyền xem phòng.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $rooms = Room::query()
            ->withoutGlobalScopes()
            ->with('building:id,name')
            ->whereIn('building_id', $permitted)
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('code', 'like', '%' . $request->string('search') . '%'))
            ->orderBy('building_id')->orderBy('floor')->orderBy('code')
            ->paginate((int) $request->integer('per_page', 20));

        $rooms->getCollection()->transform(fn (Room $r) => $this->toListItem($r));

        return response()->json($rooms);
    }

    // GET /api/admin/minihouse/rooms/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_rooms')) {
            return response()->json(['message' => 'Không có quyền xem phòng.'], 403);
        }

        $room = Room::withoutGlobalScopes()->with('building:id,name', 'amenities:id,name')->find($id);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($room)]);
    }

    // POST /api/admin/minihouse/rooms
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_rooms')) {
            return response()->json(['message' => 'Không có quyền tạo phòng.'], 403);
        }

        $data = $request->validate([
            'building_id'   => 'required|integer|exists:minihouse_buildings,id',
            'code'          => 'required|string|max:255',
            'floor'         => 'nullable|integer|min:1',
            'position_row'  => 'nullable|integer|min:1',
            'position_col'  => 'nullable|integer|min:1',
            'area'          => 'nullable|numeric|min:0',
            'price'         => 'required|numeric|min:0',
            'status'        => ['nullable', Rule::in([Room::STATUS_EMPTY, Room::STATUS_RENTED, Room::STATUS_REPAIR])],
            'note'          => 'nullable|string',
            'amenity_ids'   => 'nullable|array',
            'amenity_ids.*' => 'integer|exists:minihouse_amenities,id',
        ]);

        if (! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền tạo phòng cho toà nhà này.'], 403);
        }

        $data['status'] ??= Room::STATUS_EMPTY;
        $amenityIds = $data['amenity_ids'] ?? null;
        unset($data['amenity_ids']);

        $room = Room::create($data);

        if ($amenityIds !== null) {
            $room->amenities()->sync($amenityIds);
        }

        return response()->json(['data' => $this->toDetailItem($room->fresh(['building', 'amenities']))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/rooms/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_rooms')) {
            return response()->json(['message' => 'Không có quyền sửa phòng.'], 403);
        }

        $room = Room::withoutGlobalScopes()->find($id);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $data = $request->validate([
            'building_id'   => 'sometimes|required|integer|exists:minihouse_buildings,id',
            'code'          => 'sometimes|required|string|max:255',
            'floor'         => 'nullable|integer|min:1',
            'position_row'  => 'nullable|integer|min:1',
            'position_col'  => 'nullable|integer|min:1',
            'area'          => 'nullable|numeric|min:0',
            'price'         => 'sometimes|required|numeric|min:0',
            'status'        => ['sometimes', Rule::in([Room::STATUS_EMPTY, Room::STATUS_RENTED, Room::STATUS_REPAIR])],
            'note'          => 'nullable|string',
            'amenity_ids'   => 'nullable|array',
            'amenity_ids.*' => 'integer|exists:minihouse_amenities,id',
        ]);

        if (isset($data['building_id']) && ! $this->isBuildingAllowed($request, (int) $data['building_id'])) {
            return response()->json(['message' => 'Không có quyền chuyển phòng sang toà nhà này.'], 403);
        }

        $amenityIds = $data['amenity_ids'] ?? null;
        unset($data['amenity_ids']);

        $room->update($data);

        if ($amenityIds !== null) {
            $room->amenities()->sync($amenityIds);
        }

        return response()->json(['data' => $this->toDetailItem($room->fresh(['building', 'amenities']))]);
    }

    // DELETE /api/admin/minihouse/rooms/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_rooms')) {
            return response()->json(['message' => 'Không có quyền xoá phòng.'], 403);
        }

        $room = Room::withoutGlobalScopes()->find($id);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $room->delete();

        return response()->json(['message' => 'Đã xoá phòng.']);
    }

    private function toListItem(Room $room): array
    {
        return [
            'id'            => $room->id,
            'code'          => $room->code,
            'floor'         => $room->floor,
            'building_id'   => $room->building_id,
            'building_name' => $room->building?->name,
            'price'         => $room->price,
            'status'        => $room->status,
        ];
    }

    private function toDetailItem(Room $room): array
    {
        return [
            'id'            => $room->id,
            'building_id'   => $room->building_id,
            'building_name' => $room->building?->name,
            'code'          => $room->code,
            'floor'         => $room->floor,
            'position_row'  => $room->position_row,
            'position_col'  => $room->position_col,
            'area'          => $room->area,
            'price'         => $room->price,
            'status'        => $room->status,
            'note'          => $room->note,
            'photos'        => $room->photos,
            'amenities'     => $room->amenities->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]),
            'created_at'    => $room->created_at?->toIso8601String(),
            'updated_at'    => $room->updated_at?->toIso8601String(),
        ];
    }
}
