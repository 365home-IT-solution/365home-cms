<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Exceptions\ContractDocumentException;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Services\ContractDocumentService;

// Hợp đồng điện tử (Mức A) — mục 4 của docs/be-minihouse-contract-signing.md. Dùng lại ĐÚNG 2
// permission đã có trên nhóm Hợp đồng (view_any_contracts/update_contracts), không thêm permission
// mới. ContractDocumentService là nơi DUY NHẤT chứa logic nghiệp vụ/state machine — controller chỉ
// resolve Contract đúng ranh giới toà nhà (ScopesToMinihouseBuilding) rồi gọi service, bắt
// ContractDocumentException để trả JSON theo đúng http status/errors đã gắn sẵn.
class ContractDocumentController extends Controller
{
    use ScopesToMinihouseBuilding;

    public function __construct(private readonly ContractDocumentService $service)
    {
    }

    // GET /admin/minihouse/contracts/{id}/document — chưa có bản nào thì tự tạo draft, không 404.
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $doc = $this->service->getOrCreateDraft($contract);

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: true)]);
    }

    // PATCH /admin/minihouse/contracts/{id}/document — chỉ khi draft. KHÔNG nhận CCCD cấp ngày/nơi
    // cấp của 2 bên nữa — hồ sơ Khách thuê (TenantController) và hồ sơ Toà (BuildingController) là
    // nơi DUY NHẤT ghi, tránh 2 màn hình ghi đè lẫn nhau. Client cũ còn gửi thì validate() tự lọc bỏ.
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền sửa hợp đồng.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $data = $request->validate([
            'sign_date'     => 'sometimes|nullable|date',
            'signed_place'  => 'sometimes|nullable|string|max:255',
            'max_occupants' => 'sometimes|nullable|integer|min:1|max:50',
            'payment_day'   => 'sometimes|nullable|integer|min:1|max:28',
            'extra_terms'   => 'sometimes|nullable|string',
        ]);

        $doc = $this->service->getOrCreateDraft($contract);

        try {
            $doc = $this->service->update($doc, $data);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: true)]);
    }

    // POST /admin/minihouse/contracts/{id}/document/send — niêm phong + gửi cho khách ký.
    public function send(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền gửi hợp đồng cho khách ký.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $doc = $this->service->getOrCreateDraft($contract);

        try {
            $doc = $this->service->send($doc, $request->user(), $request);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: true)]);
    }

    // POST /admin/minihouse/contracts/{id}/document/sign — chủ trọ ký chốt (phiên admin, không OTP).
    public function sign(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền ký chốt hợp đồng.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $data = $request->validate([
            'signature'      => ['required', 'string'],
            'document_hash'  => ['required', 'string', 'size:64'],
            'consent'        => ['required', 'accepted'],
            'consent_text'   => ['required', 'string'],
            'signer_name'    => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $doc = $this->service->getOrCreateDraft($contract);

        try {
            $doc = $this->service->signAsOwner($doc, $data, $request->user(), $request);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: true)]);
    }

    // POST /admin/minihouse/contracts/{id}/document/recall
    public function recall(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_contracts')) {
            return response()->json(['message' => 'Không có quyền thu hồi hợp đồng.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $doc = $this->service->getOrCreateDraft($contract);

        try {
            $doc = $this->service->recall($doc, $request->user(), $request);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: true)]);
    }

    // GET /admin/minihouse/contracts/{id}/document/audit — "Biên bản quá trình ký".
    public function audit(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_contracts')) {
            return response()->json(['message' => 'Không có quyền xem hợp đồng.'], 403);
        }

        $contract = $this->findContract($request, $id);

        if (! $contract) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 404);
        }

        $doc = $this->service->getOrCreateDraft($contract);

        return response()->json(['data' => $this->service->auditTrail($doc)]);
    }

    // Cùng ranh giới toà nhà với ContractController — {contract} route-model-binding KHÔNG tự áp
    // global scope khi gọi qua API (xem comment đầu ContractController), phải tự lọc lại ở đây.
    private function findContract(Request $request, int $id): ?Contract
    {
        $contract = Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($id);

        if (! $contract || ! $this->isBuildingAllowed($request, $contract->room?->building_id)) {
            return null;
        }

        return $contract;
    }
}
