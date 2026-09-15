<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ResidenceDeclaration;

// Khai báo lưu trú — CHỈ lưu tham chiếu nội bộ, KHÔNG tự gửi cho ASM/dịch vụ công (xem model). API
// này phục vụ tạo/sửa/tra cứu; đánh dấu "đã khai báo" là 1 action riêng (markDeclared) vì có ràng
// buộc "đủ dữ liệu bắt buộc" (isDataComplete()) giống bản Filament.
class ResidenceDeclarationController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/residence-declarations?contract_id=&declared=&per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền xem khai báo lưu trú.'], 403);
        }

        $permitted = $this->permittedBuildingIds($request);

        // withoutGlobalScopes() ở whereHas('contract'/'room') — tránh mất khai báo lưu trú của 1
        // hợp đồng đã xoá mềm khỏi danh sách (cùng lỗi lớp đã sửa ở ScopedToActiveBuildingViaContract).
        $declarations = ResidenceDeclaration::query()
            ->withoutGlobalScopes()
            ->with([
                'contract'      => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room' => fn ($q) => $q->withoutGlobalScopes(),
                'tenant:id,fullname',
            ])
            ->whereHas('contract', fn ($q) => $q->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q2) => $q2->withoutGlobalScopes()->whereIn('building_id', $permitted),
            ))
            ->when($request->filled('contract_id'), fn ($q) => $q->where('contract_id', $request->integer('contract_id')))
            ->when($request->filled('declared'), fn ($q) => $request->boolean('declared')
                ? $q->whereNotNull('declared_at')
                : $q->whereNull('declared_at'))
            ->orderByDesc('checked_in_at')
            ->paginate((int) $request->integer('per_page', 20));

        $declarations->getCollection()->transform(fn (ResidenceDeclaration $d) => $this->toListItem($d));

        return response()->json($declarations);
    }

    // GET /api/admin/minihouse/residence-declarations/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền xem khai báo lưu trú.'], 403);
        }

        $declaration = $this->findAllowed($request, $id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy khai báo lưu trú.'], 404);
        }

        return response()->json(['data' => $this->toDetailItem($declaration)]);
    }

    // POST /api/admin/minihouse/residence-declarations
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền tạo khai báo lưu trú.'], 403);
        }

        $data = $request->validate([
            'contract_id'        => 'required|integer|exists:minihouse_contracts,id',
            'tenant_id'          => 'required|integer|exists:minihouse_tenants,id',
            'full_name'          => 'required|string|max:255',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|string|max:20',
            'cccd_number'        => 'nullable|string|max:20',
            'nationality'        => 'nullable|string|max:255',
            'document_type'      => 'nullable|string|max:255',
            'phone_number'       => 'nullable|string|max:20',
            'checked_in_at'      => 'nullable|date',
            // Mirror ResidenceDeclarationForm — chặn "Ngày đi dự kiến" trước "Ngày đến", tránh dữ
            // liệu vô lý làm sai thời hạn khai báo (declarationDeadline()) và export cho công an.
            'checked_out_at'     => 'nullable|date|after_or_equal:checked_in_at',
            'room_number'        => 'nullable|string|max:255',
            'stay_address'       => 'nullable|string|max:255',
            'reason_for_stay'    => 'nullable|string|max:255',
            'custom_reason'      => 'nullable|string|max:255',
            'current_residence'  => 'nullable|string|max:255',
            'residence_type'     => 'nullable|string|max:255',
            'province'           => 'nullable|string|max:255',
            'ward'               => 'nullable|string|max:255',
            'address_detail'     => 'nullable|string|max:255',
            'notes'              => 'nullable|string',
        ]);

        $contract = Contract::withoutGlobalScopes()->with('room')->find($data['contract_id']);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return response()->json(['message' => 'Không có quyền tạo khai báo lưu trú cho hợp đồng này.'], 403);
        }

        $declaration = ResidenceDeclaration::create($data);

        return response()->json(['data' => $this->toDetailItem($declaration->fresh(['contract.room', 'tenant']))], 201);
    }

    // PUT/PATCH /api/admin/minihouse/residence-declarations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền sửa khai báo lưu trú.'], 403);
        }

        $declaration = $this->findAllowed($request, $id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy khai báo lưu trú.'], 404);
        }

        $data = $request->validate([
            'full_name'          => 'sometimes|required|string|max:255',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|string|max:20',
            'cccd_number'        => 'nullable|string|max:20',
            'nationality'        => 'nullable|string|max:255',
            'document_type'      => 'nullable|string|max:255',
            'phone_number'       => 'nullable|string|max:20',
            'checked_in_at'      => 'nullable|date',
            // Mirror ResidenceDeclarationForm — chặn "Ngày đi dự kiến" trước "Ngày đến", tránh dữ
            // liệu vô lý làm sai thời hạn khai báo (declarationDeadline()) và export cho công an.
            'checked_out_at'     => 'nullable|date|after_or_equal:checked_in_at',
            'room_number'        => 'nullable|string|max:255',
            'stay_address'       => 'nullable|string|max:255',
            'reason_for_stay'    => 'nullable|string|max:255',
            'custom_reason'      => 'nullable|string|max:255',
            'current_residence'  => 'nullable|string|max:255',
            'residence_type'     => 'nullable|string|max:255',
            'province'           => 'nullable|string|max:255',
            'ward'               => 'nullable|string|max:255',
            'address_detail'     => 'nullable|string|max:255',
            'notes'              => 'nullable|string',
        ]);

        $declaration->update($data);

        return response()->json(['data' => $this->toDetailItem($declaration->fresh(['contract.room', 'tenant']))]);
    }

    // POST /api/admin/minihouse/residence-declarations/{id}/mark-declared
    // Đánh dấu "đã nộp thủ công" — chặn nếu dữ liệu bắt buộc theo Luật Cư trú còn thiếu, giống hệt
    // ràng buộc ở bản Filament (xem ResidenceDeclaration::isDataComplete()).
    public function markDeclared(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền cập nhật khai báo lưu trú.'], 403);
        }

        $declaration = $this->findAllowed($request, $id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy khai báo lưu trú.'], 404);
        }

        if (! $declaration->isDataComplete()) {
            return response()->json([
                'message' => 'Còn thiếu dữ liệu bắt buộc, chưa đánh dấu "đã khai báo" được.',
                'missing_fields' => $declaration->missingRequiredFieldLabels(),
            ], 422);
        }

        $declaration->update(['declared_at' => now(), 'declared_by' => $request->user()->id]);

        return response()->json(['data' => $this->toDetailItem($declaration->fresh(['contract.room', 'tenant']))]);
    }

    // DELETE /api/admin/minihouse/residence-declarations/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_residence_declarations')) {
            return response()->json(['message' => 'Không có quyền xoá khai báo lưu trú.'], 403);
        }

        $declaration = $this->findAllowed($request, $id);

        if (! $declaration) {
            return response()->json(['message' => 'Không tìm thấy khai báo lưu trú.'], 404);
        }

        $declaration->delete();

        return response()->json(['message' => 'Đã xoá khai báo lưu trú.']);
    }

    // Contract/Room/Tenant dùng SoftDeletes riêng — eager-load thường vẫn áp scope đó ở quan hệ lồng
    // nhau, nên 1 hợp đồng bị xoá mềm sẽ làm building_id ở dưới thành null, CHẶN NHẦM quyền xem 1
    // khai báo lưu trú lịch sử hợp lệ (cùng lỗi lớp đã gặp và sửa ở InvoiceController/
    // InvoicePrintController).
    private function findAllowed(Request $request, int $id): ?ResidenceDeclaration
    {
        $declaration = ResidenceDeclaration::withoutGlobalScopes()
            ->with([
                'contract'      => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room' => fn ($q) => $q->withoutGlobalScopes(),
                'tenant'        => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($id);

        if (! $declaration || ! $this->isBuildingAllowed($request, $declaration->contract?->room?->building_id)) {
            return null;
        }

        return $declaration;
    }

    private function toListItem(ResidenceDeclaration $declaration): array
    {
        return [
            'id'             => $declaration->id,
            'contract_id'    => $declaration->contract_id,
            'room_code'      => $declaration->contract?->room?->code,
            'tenant_id'      => $declaration->tenant_id,
            'full_name'      => $declaration->full_name,
            'checked_in_at'  => $declaration->checked_in_at?->toIso8601String(),
            'is_declared'    => $declaration->isDeclared(),
            'is_overdue'     => $declaration->isOverdue(),
        ];
    }

    private function toDetailItem(ResidenceDeclaration $declaration): array
    {
        return [
            'id'                 => $declaration->id,
            'contract_id'        => $declaration->contract_id,
            'room_code'          => $declaration->contract?->room?->code,
            'tenant_id'          => $declaration->tenant_id,
            'full_name'          => $declaration->full_name,
            'date_of_birth'      => $declaration->date_of_birth?->toDateString(),
            'gender'             => $declaration->gender,
            'cccd_number'        => $declaration->cccd_number,
            'nationality'        => $declaration->nationality,
            'document_type'      => $declaration->document_type,
            'phone_number'       => $declaration->phone_number,
            'checked_in_at'      => $declaration->checked_in_at?->toIso8601String(),
            'checked_out_at'     => $declaration->checked_out_at?->toIso8601String(),
            'room_number'        => $declaration->room_number,
            'stay_address'       => $declaration->stay_address,
            'reason_for_stay'    => $declaration->reason_for_stay,
            'custom_reason'      => $declaration->custom_reason,
            'current_residence'  => $declaration->current_residence,
            'residence_type'     => $declaration->residence_type,
            'province'           => $declaration->province,
            'ward'               => $declaration->ward,
            'address_detail'     => $declaration->address_detail,
            'notes'              => $declaration->notes,
            'declared_at'        => $declaration->declared_at?->toIso8601String(),
            'declared_by'        => $declaration->declared_by,
            'is_declared'        => $declaration->isDeclared(),
            'is_overdue'         => $declaration->isOverdue(),
            'is_data_complete'   => $declaration->isDataComplete(),
            'missing_fields'     => $declaration->missingRequiredFieldLabels(),
        ];
    }
}
