<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Exceptions\ContractDocumentException;
use Modules\Minihouse\App\Models\ContractDocument;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\ContractDocumentService;
use Modules\Minihouse\App\Services\TenantPortalService;

// Hợp đồng điện tử (Mức A) phía khách thuê — mục 5 của docs/be-minihouse-contract-signing.md.
// Kiểm tra hợp đồng thuộc ĐÚNG tenant đang đăng nhập bằng TenantPortalService::tenantContracts()
// (cùng cách TenantPortalApiController::showContract() đang kiểm tra), KHÔNG tin route-model-binding
// {contract} vì Contract::ScopedToActiveBuildingViaRoom không tự áp cho tenant.
class ContractDocumentPortalController extends Controller
{
    public function __construct(private readonly ContractDocumentService $service)
    {
    }

    // GET /minihouse/portal/contracts/{contract}/document
    public function show(Request $request, int $contract): JsonResponse
    {
        $tenant = $this->tenant($request);
        $found  = TenantPortalService::tenantContracts($tenant)->firstWhere('id', $contract);

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 403);
        }

        $doc = $this->service->getOrCreateDraft($found);

        if ($doc->status === ContractDocument::STATUS_DRAFT) {
            return response()->json(['message' => 'Chưa có hợp đồng để ký.'], 404);
        }

        // Bằng chứng khách ĐÃ ĐƯỢC ĐƯA văn bản trước khi ký (mục 5.1 note).
        $this->service->markViewedByTenant($doc, $tenant, $request);

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: false)]);
    }

    // POST /minihouse/portal/contracts/{contract}/document/otp
    public function otp(Request $request, int $contract): JsonResponse
    {
        $tenant = $this->tenant($request);
        $found  = TenantPortalService::tenantContracts($tenant)->firstWhere('id', $contract);

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 403);
        }

        $doc = $this->service->getOrCreateDraft($found);

        try {
            $result = $this->service->requestTenantOtp($doc, $request);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json([
            'message'        => 'Mã xác thực đã gửi qua ' . ($result['channel'] === 'zalo' ? 'Zalo.' : 'SMS.'),
            'channel'        => $result['channel'],
            'otp_request_id' => $result['request_id'],
        ]);
    }

    // POST /minihouse/portal/contracts/{contract}/document/sign
    public function sign(Request $request, int $contract): JsonResponse
    {
        $tenant = $this->tenant($request);
        $found  = TenantPortalService::tenantContracts($tenant)->firstWhere('id', $contract);

        if (! $found) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng.'], 403);
        }

        $data = $request->validate([
            'signature'       => ['required', 'string'],
            'otp_code'        => ['required', 'string', 'size:6'],
            'otp_request_id'  => ['required', 'string'],
            'document_hash'   => ['required', 'string', 'size:64'],
            'consent'         => ['required', 'accepted'],
            'consent_text'    => ['required', 'string'],
            'signer_name'     => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $doc = $this->service->getOrCreateDraft($found);

        try {
            $doc = $this->service->signAsTenant($doc, $data, $tenant, $request);
        } catch (ContractDocumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], $e->httpStatus);
        }

        return response()->json(['data' => $this->service->buildFields($doc, includeInternal: false)]);
    }

    private function tenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        return $tenant;
    }
}
