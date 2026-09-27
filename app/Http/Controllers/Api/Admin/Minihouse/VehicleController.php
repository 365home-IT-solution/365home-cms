<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Services\VehicleService;

// Xe của khách thuê (bản đơn giản: biển số, loại xe, tên xe) — bản API của tab "Phương tiện" trên
// hợp đồng (Filament). Ranh giới quyền theo building_id (Toà nhà) qua ScopesToMinihouseBuilding.
// Vượt giới hạn xe CHỈ trả "warnings" trong response, không chặn.
class VehicleController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/vehicles?building_id=&status=&vehicle_type=&contract_id=&tenant_id=&search=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_vehicles')) {
            return response()->json(['message' => 'Không có quyền xem xe khách thuê.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        if ($request->filled('building_id') && ! in_array((int) $request->integer('building_id'), $permitted, true)) {
            return response()->json(['message' => 'Không có quyền xem toà nhà này.'], 403);
        }

        $vehicles = $this->baseQuery($permitted)
            ->when($request->filled('building_id'), fn ($q) => $q->where('building_id', $request->integer('building_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('vehicle_type'), fn ($q) => $q->where('vehicle_type', $request->input('vehicle_type')))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = (string) $request->input('search');
                $q->where(fn ($w) => $w->where('plate_display', 'like', "%{$s}%")
                    ->orWhere('name', 'like', "%{$s}%")
                    ->orWhere('plate', 'like', '%' . VehicleService::normalizePlate($s) . '%'));
            })
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 20));

        $vehicles->getCollection()->transform(fn (Vehicle $v) => $this->toItem($v));

        return response()->json($vehicles);
    }

    // GET /api/admin/minihouse/vehicles/lookup?plate= — tra nhanh biển số (bảo vệ): xe này của phòng nào?
    public function lookup(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_vehicles')) {
            return response()->json(['message' => 'Không có quyền xem xe khách thuê.'], 403);
        }

        $plate = VehicleService::normalizePlate((string) $request->query('plate', ''));

        if ($plate === '') {
            return response()->json(['message' => 'Thiếu biển số.'], 422);
        }

        $vehicles = $this->baseQuery($this->permittedBuildingIds($request))
            ->where('plate', 'like', "%{$plate}%")
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Vehicle $v) => $this->toItem($v));

        return response()->json(['data' => $vehicles]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_vehicles')) {
            return response()->json(['message' => 'Không có quyền xem xe khách thuê.'], 403);
        }

        $vehicle = $this->find($request, $id);

        return $vehicle
            ? response()->json(['data' => $this->toItem($vehicle)])
            : response()->json(['message' => 'Không tìm thấy xe.'], 404);
    }

    // POST /api/admin/minihouse/vehicles — contract_id bắt buộc (hợp đồng đang hiệu lực); tenant/toà nhà
    // tự suy ra từ hợp đồng. status mặc định "active" (nhân viên tự thêm = đã duyệt).
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_vehicles')) {
            return response()->json(['message' => 'Không có quyền thêm xe.'], 403);
        }

        $data = $request->validate([
            'contract_id'  => 'required|integer',
            'plate'        => 'required|string|max:30',
            'vehicle_type' => ['required', Rule::in(array_keys(Vehicle::TYPES))],
            'name'         => 'required|string|max:100',
            'status'       => ['nullable', Rule::in([Vehicle::STATUS_ACTIVE, Vehicle::STATUS_PENDING])],
            'document_photo' => 'nullable|image|max:5120',
        ]);

        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($data['contract_id']);

        if (! $contract || $contract->status !== Contract::STATUS_ACTIVE || ! $contract->room) {
            return response()->json(['message' => 'Hợp đồng không tồn tại hoặc không còn hiệu lực.'], 422);
        }

        $buildingId = (int) $contract->room->building_id;

        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Không có quyền trên toà nhà của hợp đồng này.'], 403);
        }

        $plate = VehicleService::normalizePlate($data['plate']);

        if ($plate === '' || VehicleService::plateInUse($buildingId, $plate)) {
            return response()->json(['message' => 'Biển số không hợp lệ hoặc đã có xe đang gửi/chờ duyệt trong toà nhà.'], 422);
        }

        $status  = $data['status'] ?? Vehicle::STATUS_ACTIVE;
        $vehicle = new Vehicle([
            'building_id'   => $buildingId,
            'contract_id'   => $contract->id,
            'tenant_id'     => $contract->tenant_id,
            'plate_display' => $data['plate'],
            'vehicle_type'  => $data['vehicle_type'],
            'name'          => $data['name'],
            'status'        => $status,
            'requested_by'  => 'staff',
        ]);

        // Cảnh báo tính TRƯỚC khi lưu (chưa có id nên limitWarnings() không phải loại trừ chính nó).
        $warnings = VehicleService::limitWarnings($vehicle);

        if ($request->hasFile('document_photo')) {
            $vehicle->document_photo = $request->file('document_photo')->store('minihouse/vehicles', 'public');
        }

        $vehicle->save();

        return response()->json(['data' => $this->toItem($vehicle->fresh()), 'warnings' => $warnings], 201);
    }

    // PUT /api/admin/minihouse/vehicles/{id} — mọi field tuỳ chọn; đổi trạng thái qua approve/reject/deactivate.
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_vehicles')) {
            return response()->json(['message' => 'Không có quyền sửa xe.'], 403);
        }

        $vehicle = $this->find($request, $id);

        if (! $vehicle) {
            return response()->json(['message' => 'Không tìm thấy xe.'], 404);
        }

        $data = $request->validate([
            'plate'        => 'sometimes|string|max:30',
            'vehicle_type' => ['sometimes', Rule::in(array_keys(Vehicle::TYPES))],
            'name'         => 'sometimes|string|max:100',
            'document_photo' => 'nullable|image|max:5120',
        ]);

        if (isset($data['plate'])) {
            $plate = VehicleService::normalizePlate($data['plate']);

            if ($plate === '' || VehicleService::plateInUse((int) $vehicle->building_id, $plate, $vehicle->id)) {
                return response()->json(['message' => 'Biển số không hợp lệ hoặc đã có xe khác đang gửi/chờ duyệt trong toà nhà.'], 422);
            }

            $vehicle->plate_display = $data['plate'];
        }

        foreach (['vehicle_type', 'name'] as $field) {
            if ($request->has($field)) {
                $vehicle->{$field} = $data[$field];
            }
        }

        if ($request->hasFile('document_photo')) {
            if ($vehicle->document_photo) {
                Storage::disk('public')->delete($vehicle->document_photo);
            }

            $vehicle->document_photo = $request->file('document_photo')->store('minihouse/vehicles', 'public');
        }

        $vehicle->save();

        return response()->json(['data' => $this->toItem($vehicle->fresh()), 'warnings' => VehicleService::limitWarnings($vehicle)]);
    }

    // POST /api/admin/minihouse/vehicles/{id}/approve — duyệt xe khách tự khai (pending → active).
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'update_vehicles', function (Vehicle $v) use ($request) {
            if ($v->status !== Vehicle::STATUS_PENDING) {
                return 'Chỉ duyệt được xe đang chờ duyệt.';
            }

            $warnings = VehicleService::limitWarnings($v);
            VehicleService::approve($v, (string) $request->user()->id);

            return $warnings;
        });
    }

    // POST /api/admin/minihouse/vehicles/{id}/reject  { reason }
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->transition($request, $id, 'update_vehicles', function (Vehicle $v) use ($request, $data) {
            if ($v->status !== Vehicle::STATUS_PENDING) {
                return 'Chỉ từ chối được xe đang chờ duyệt.';
            }

            VehicleService::reject($v, (string) $request->user()->id, $data['reason']);

            return [];
        });
    }

    // POST /api/admin/minihouse/vehicles/{id}/deactivate — ngưng gửi (active → inactive, end_date = hôm nay).
    public function deactivate(Request $request, int $id): JsonResponse
    {
        return $this->transition($request, $id, 'update_vehicles', function (Vehicle $v) {
            if ($v->status !== Vehicle::STATUS_ACTIVE) {
                return 'Chỉ ngưng được xe đang gửi.';
            }

            VehicleService::deactivate($v);

            return [];
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_vehicles')) {
            return response()->json(['message' => 'Không có quyền xoá xe.'], 403);
        }

        $vehicle = $this->find($request, $id);

        if (! $vehicle) {
            return response()->json(['message' => 'Không tìm thấy xe.'], 404);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Đã xoá xe.']);
    }

    // ── Dùng chung ────────────────────────────────────────────────────────────

    // $action trả về chuỗi = lỗi nghiệp vụ (422), mảng = danh sách cảnh báo.
    private function transition(Request $request, int $id, string $permission, \Closure $action): JsonResponse
    {
        if (! $this->hasPermission($request, $permission)) {
            return response()->json(['message' => 'Không có quyền thực hiện thao tác này.'], 403);
        }

        $vehicle = $this->find($request, $id);

        if (! $vehicle) {
            return response()->json(['message' => 'Không tìm thấy xe.'], 404);
        }

        $result = $action($vehicle);

        if (is_string($result)) {
            return response()->json(['message' => $result], 422);
        }

        return response()->json(['data' => $this->toItem($vehicle->fresh()), 'warnings' => $result]);
    }

    private function baseQuery(array $permitted)
    {
        return Vehicle::query()
            ->withoutGlobalScope('activeBuilding')
            ->with([
                'building:id,name',
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'contract' => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->whereIn('building_id', $permitted);
    }

    private function find(Request $request, int $id): ?Vehicle
    {
        return $this->baseQuery($this->permittedBuildingIds($request))->find($id);
    }

    /** @return array<string, mixed> */
    public static function toItem(Vehicle $v): array
    {
        return [
            'id'                 => $v->id,
            'building_id'        => $v->building_id,
            'building_name'      => $v->building?->name,
            'contract_id'        => $v->contract_id,
            'room_code'          => $v->contract?->room?->code,
            'tenant'             => $v->tenant ? ['id' => $v->tenant->id, 'fullname' => $v->tenant->fullname, 'phone' => $v->tenant->phone] : null,
            'plate'              => $v->plate,
            'plate_display'      => $v->plate_display,
            'vehicle_type'       => $v->vehicle_type,
            'vehicle_type_label' => $v->typeLabel(),
            'name'               => $v->name,
            'document_photo_url' => $v->documentPhotoUrl(),
            'effective_fee'      => VehicleService::monthlyFee($v),
            'status'             => $v->status,
            'status_label'       => $v->statusLabel(),
            'start_date'         => $v->start_date?->toDateString(),
            'end_date'           => $v->end_date?->toDateString(),
            'requested_by'       => $v->requested_by,
            'approved_at'        => $v->approved_at?->toDateTimeString(),
            'reject_reason'      => $v->reject_reason,
        ];
    }
}
