<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;

class TenantController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/tenants?search=&room_id=&per_page=
    // LƯU Ý: khách thuê KHÔNG đang ở phòng nào (room_id null — đã trả phòng) sẽ không hiện trong
    // danh sách lọc theo toà — giống hệt hành vi ScopedToActiveBuildingViaRoom bên panel Filament
    // (xem session trước: "tenant với room_id null bị ẩn khi có bộ lọc toà nhà đang bật").
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem khách thuê.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        $tenants = Tenant::query()
            ->withoutGlobalScopes()
            ->with('room:id,code,building_id')
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', $permitted))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q2) => $q2
                ->where('fullname', 'like', '%' . $request->string('search') . '%')
                ->orWhere('id_card_number', 'like', '%' . $request->string('search') . '%')
                ->orWhere('phone', 'like', '%' . $request->string('search') . '%')))
            ->orderBy('fullname')
            ->paginate((int) $request->integer('per_page', 20));

        $tenants->getCollection()->transform(fn (Tenant $t) => $this->toListItem($t));

        return response()->json($tenants);
    }

    // GET /api/admin/minihouse/tenants/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem khách thuê.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $tenant || ! $this->tenantAllowed($request, $tenant)) {
            return response()->json(['message' => 'Không tìm thấy khách thuê.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($tenant)]);
    }

    // POST /api/admin/minihouse/tenants
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_tenants')) {
            return response()->json(['message' => 'Không có quyền tạo khách thuê.'], 403);
        }

        $data = $request->validate([
            'fullname'                 => 'required|string|max:255',
            'phone'                    => 'nullable|string|max:20',
            'id_card_number'           => 'nullable|string|max:20',
            'id_card_front'            => 'nullable|string|max:2048',
            'id_card_back'             => 'nullable|string|max:2048',
            'date_of_birth'            => 'nullable|date',
            'gender'                   => ['nullable', Rule::in([Tenant::GENDER_MALE, Tenant::GENDER_FEMALE, Tenant::GENDER_OTHER])],
            'hometown'                 => 'nullable|string|max:255',
            'permanent_address'        => 'nullable|string|max:255',
            'occupation'               => 'nullable|string|max:255',
            'workplace'                => 'nullable|string|max:255',
            'emergency_contact_name'   => 'nullable|string|max:255',
            'emergency_contact_phone'  => 'nullable|string|max:20',
            'room_id'                  => 'nullable|integer|exists:minihouse_rooms,id',
            'note'                     => 'nullable|string',
        ]);

        // room_id CÓ giá trị thì phòng đó phải thuộc toà được phép — room_id null (chưa gán phòng)
        // thì tạo được bình thường, không có toà nào để kiểm tra.
        if (! empty($data['room_id'])) {
            $room = Room::withoutGlobalScopes()->find($data['room_id']);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return response()->json(['message' => 'Không có quyền gán khách thuê vào phòng của toà nhà này.'], 403);
            }
        }

        $tenant = Tenant::create($data);

        return response()->json(['data' => $this->toDetailItem($tenant->fresh(['room' => fn ($q) => $q->withoutGlobalScopes()]))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/tenants/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền sửa khách thuê.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $tenant || ! $this->tenantAllowed($request, $tenant)) {
            return response()->json(['message' => 'Không tìm thấy khách thuê.'], 404);
        }

        $data = $request->validate([
            'fullname'                 => 'sometimes|required|string|max:255',
            'phone'                    => 'nullable|string|max:20',
            'id_card_number'           => 'nullable|string|max:20',
            'id_card_front'            => 'nullable|string|max:2048',
            'id_card_back'             => 'nullable|string|max:2048',
            'date_of_birth'            => 'nullable|date',
            'gender'                   => ['nullable', Rule::in([Tenant::GENDER_MALE, Tenant::GENDER_FEMALE, Tenant::GENDER_OTHER])],
            'hometown'                 => 'nullable|string|max:255',
            'permanent_address'        => 'nullable|string|max:255',
            'occupation'               => 'nullable|string|max:255',
            'workplace'                => 'nullable|string|max:255',
            'emergency_contact_name'   => 'nullable|string|max:255',
            'emergency_contact_phone'  => 'nullable|string|max:20',
            'room_id'                  => 'nullable|integer|exists:minihouse_rooms,id',
            'note'                     => 'nullable|string',
        ]);

        if (array_key_exists('room_id', $data) && ! empty($data['room_id'])) {
            $room = Room::withoutGlobalScopes()->find($data['room_id']);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return response()->json(['message' => 'Không có quyền chuyển khách thuê sang phòng của toà nhà này.'], 403);
            }
        }

        $tenant->update($data);

        return response()->json(['data' => $this->toDetailItem($tenant->fresh(['room' => fn ($q) => $q->withoutGlobalScopes()]))]);
    }

    // DELETE /api/admin/minihouse/tenants/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_tenants')) {
            return response()->json(['message' => 'Không có quyền xoá khách thuê.'], 403);
        }

        $tenant = Tenant::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $tenant || ! $this->tenantAllowed($request, $tenant)) {
            return response()->json(['message' => 'Không tìm thấy khách thuê.'], 404);
        }

        $tenant->delete();

        return response()->json(['message' => 'Đã xoá khách thuê.']);
    }

    // Khách CHƯA có phòng (room_id null) coi như luôn "được phép" xem/sửa (không thuộc riêng toà
    // nào để mà chặn) — chỉ chặn khi khách đang ở 1 phòng thuộc toà NGOÀI phạm vi được quản lý.
    private function tenantAllowed(Request $request, Tenant $tenant): bool
    {
        if (! $tenant->room_id) {
            return true;
        }

        return $this->isBuildingAllowed($request, $tenant->room?->building_id);
    }

    private function toListItem(Tenant $tenant): array
    {
        return [
            'id'             => $tenant->id,
            'fullname'       => $tenant->fullname,
            'phone'          => $tenant->phone,
            'id_card_number' => $tenant->id_card_number,
            'room_id'        => $tenant->room_id,
            'room_code'      => $tenant->room?->code,
        ];
    }

    private function toDetailItem(Tenant $tenant): array
    {
        return [
            'id'                       => $tenant->id,
            'fullname'                 => $tenant->fullname,
            'phone'                    => $tenant->phone,
            'id_card_number'           => $tenant->id_card_number,
            'id_card_front'            => $tenant->id_card_front,
            'id_card_back'             => $tenant->id_card_back,
            'date_of_birth'            => $tenant->date_of_birth?->toDateString(),
            'gender'                   => $tenant->gender,
            'hometown'                 => $tenant->hometown,
            'permanent_address'        => $tenant->permanent_address,
            'occupation'               => $tenant->occupation,
            'workplace'                => $tenant->workplace,
            'emergency_contact_name'   => $tenant->emergency_contact_name,
            'emergency_contact_phone'  => $tenant->emergency_contact_phone,
            'residence_declared'       => $tenant->residence_declared,
            'residence_declared_at'    => $tenant->residence_declared_at?->toDateString(),
            'room_id'                  => $tenant->room_id,
            'room_code'                => $tenant->room?->code,
            'note'                     => $tenant->note,
            'created_at'               => $tenant->created_at?->toIso8601String(),
            'updated_at'               => $tenant->updated_at?->toIso8601String(),
        ];
    }
}
