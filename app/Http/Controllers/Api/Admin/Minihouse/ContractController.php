<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Room;

// CRUD cơ bản cho Hợp đồng. Các luồng nghiệp vụ nâng cao chỉ có ở panel Filament (chưa đưa vào API
// đợt này — xem EditContract::getHeaderActions()): Gia hạn (renewContract, có ghi log
// ContractRenewal), Thanh lý/Hoàn cọc (checkoutContract), Chuyển phòng (transferRoom, tạo hợp đồng
// mới + copy người ở cùng/phụ thu). API này chỉ tạo/sửa/xoá hợp đồng thô — muốn làm các luồng trên
// qua API thì cần bổ sung thêm action riêng, chưa nằm trong phạm vi lần này.
class ContractController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/contracts?status=&room_id=&tenant_id=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        $contracts = Contract::query()
            ->withoutGlobalScopes()
            ->with(['room:id,code,building_id', 'tenant:id,fullname'])
            ->whereHas('room', fn ($q) => $q->whereIn('building_id', $permitted))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('room_id'), fn ($q) => $q->where('room_id', $request->integer('room_id')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        $contracts->getCollection()->transform(fn (Contract $c) => $this->toListItem($c));

        return response()->json($contracts);
    }

    // GET /api/admin/minihouse/contracts/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with(['room.building', 'tenant'])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($contract)]);
    }

    // POST /api/admin/minihouse/contracts
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_contracts')) {
            return response()->json(['message' => 'Không có quyền tạo hợp đồng.'], 403);
        }

        $data = $request->validate([
            'room_id'              => 'required|integer|exists:minihouse_rooms,id',
            'tenant_id'            => 'required|integer|exists:minihouse_tenants,id',
            'start_date'           => 'required|date',
            'end_date'             => 'nullable|date|after:start_date',
            'monthly_price'        => 'required|numeric|min:0',
            'deposit_amount'       => 'nullable|numeric|min:0',
            'status'               => ['nullable', Rule::in([Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRED, Contract::STATUS_CANCELLED])],
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
        ]);

        $room = Room::withoutGlobalScopes()->find($data['room_id']);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return response()->json(['message' => 'Không có quyền tạo hợp đồng cho phòng của toà nhà này.'], 403);
        }

        $data['status'] ??= Contract::STATUS_ACTIVE;

        // Chặn 1 phòng có 2 hợp đồng "Đang hiệu lực" cùng lúc — giống rule ở ContractForm.
        if ($data['status'] === Contract::STATUS_ACTIVE) {
            $conflict = Contract::withoutGlobalScopes()->where('room_id', $data['room_id'])->where('status', Contract::STATUS_ACTIVE)->exists();

            if ($conflict) {
                return response()->json(['message' => 'Phòng này đang có hợp đồng khác còn hiệu lực.'], 422);
            }
        }

        // Contract/TenantObserver tự đồng bộ Room.status/Tenant.room_id/ContractTenant khi tạo —
        // xem MinihouseServiceProvider::boot(). Không cần tự làm lại ở đây.
        $contract = Contract::create($data);

        return response()->json(['data' => $this->toDetailItem($contract->fresh(['room.building', 'tenant']))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/contracts/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền sửa hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with('room')->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $data = $request->validate([
            'room_id'              => 'sometimes|required|integer|exists:minihouse_rooms,id',
            'tenant_id'            => 'sometimes|required|integer|exists:minihouse_tenants,id',
            'start_date'           => 'sometimes|required|date',
            'end_date'             => 'nullable|date|after:start_date',
            'monthly_price'        => 'sometimes|required|numeric|min:0',
            'deposit_amount'       => 'nullable|numeric|min:0',
            'status'               => ['sometimes', Rule::in([Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRED, Contract::STATUS_CANCELLED])],
            'electric_unit_price'  => 'nullable|numeric|min:0',
            'water_unit_price'     => 'nullable|numeric|min:0',
        ]);

        if (isset($data['room_id'])) {
            $room = Room::withoutGlobalScopes()->find($data['room_id']);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return response()->json(['message' => 'Không có quyền chuyển hợp đồng sang phòng của toà nhà này.'], 403);
            }
        }

        $contract->update($data);

        return response()->json(['data' => $this->toDetailItem($contract->fresh(['room.building', 'tenant']))]);
    }

    // DELETE /api/admin/minihouse/contracts/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_contracts')) {
            return response()->json(['message' => 'Không có quyền xoá hợp đồng.'], 403);
        }

        $contract = Contract::withoutGlobalScopes()->with('room')->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $contract->delete();

        return response()->json(['message' => 'Đã xoá hợp đồng.']);
    }

    private function toListItem(Contract $contract): array
    {
        return [
            'id'            => $contract->id,
            'room_id'       => $contract->room_id,
            'room_code'     => $contract->room?->code,
            'tenant_id'     => $contract->tenant_id,
            'tenant_name'   => $contract->tenant?->fullname,
            'start_date'    => $contract->start_date?->toDateString(),
            'end_date'      => $contract->end_date?->toDateString(),
            'monthly_price' => $contract->monthly_price,
            'status'        => $contract->status,
        ];
    }

    private function toDetailItem(Contract $contract): array
    {
        return [
            'id'                            => $contract->id,
            'room_id'                       => $contract->room_id,
            'room_code'                     => $contract->room?->code,
            'building_id'                   => $contract->room?->building_id,
            'building_name'                 => $contract->room?->building?->name,
            'tenant_id'                     => $contract->tenant_id,
            'tenant_name'                   => $contract->tenant?->fullname,
            'start_date'                    => $contract->start_date?->toDateString(),
            'end_date'                      => $contract->end_date?->toDateString(),
            'monthly_price'                 => $contract->monthly_price,
            'deposit_amount'                => $contract->deposit_amount,
            'status'                        => $contract->status,
            'electric_unit_price'           => $contract->electric_unit_price,
            'water_unit_price'              => $contract->water_unit_price,
            'checkout_at'                   => $contract->checkout_at?->toDateString(),
            'deposit_refunded_amount'       => $contract->deposit_refunded_amount,
            'deposit_deduction_reason'      => $contract->deposit_deduction_reason,
            'transferred_to_contract_id'    => $contract->transferred_to_contract_id,
            'transferred_from_contract_id'  => $contract->transferred_from_contract_id,
            'created_at'                    => $contract->created_at?->toIso8601String(),
            'updated_at'                    => $contract->updated_at?->toIso8601String(),
        ];
    }
}
