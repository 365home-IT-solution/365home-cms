<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Minihouse\App\Models\ContractDocument;

// GET /api/minihouse/contract-verify/{code} — công khai, KHÔNG cần đăng nhập (xem
// docs/be-minihouse-contract-signing.md mục 9). Trả TỐI THIỂU đủ để đối chiếu, không lộ CCCD/địa
// chỉ/giá thuê — bên thứ ba cầm bản in đối chiếu mã in ở chân trang PDF với dữ liệu ở đây.
class ContractVerifyController extends Controller
{
    public function show(string $code): JsonResponse
    {
        $document = ContractDocument::where('verify_code', $code)
            ->with(['contract' => fn ($q) => $q->withoutGlobalScopes(), 'signatures'])
            ->first();

        if (! $document) {
            return response()->json(['message' => 'Không tìm thấy hợp đồng với mã này.'], 404);
        }

        $contract = $document->contract;
        $room     = \Modules\Minihouse\App\Models\Room::withoutGlobalScope('activeBuilding')->find($contract?->room_id);
        $building = $room ? \Modules\Minihouse\App\Models\Building::withoutGlobalScopes()->find($room->building_id) : null;

        $tenantSignature = $document->signatures->firstWhere('party', 'tenant');
        $ownerSignature  = $document->signatures->firstWhere('party', 'owner');

        return response()->json(['data' => [
            'no'          => $document->no,
            'status'      => $document->status,
            'sealed_hash' => $document->sealed_hash,
            'final_hash'  => $document->final_hash,
            'sealed_at'   => $document->sealed_at?->toIso8601String(),
            'parties'     => [
                'owner'  => $this->maskName($document->snapshot['owner_name'] ?? null),
                'tenant' => $this->maskName($document->snapshot['tenant_name'] ?? null),
            ],
            'room'        => $room ? ($room->code . ' · ' . ($building?->name ?? '')) : null,
            'signed_at'   => [
                'tenant' => $tenantSignature?->signed_at?->toIso8601String(),
                'owner'  => $ownerSignature?->signed_at?->toIso8601String(),
            ],
        ]]);
    }

    // Giữ HỌ + KÝ TỰ ĐẦU TÊN ĐỆM/TÊN, che phần còn lại — VD "Trần Thị B" — đủ để đối chiếu, không
    // lộ đầy đủ danh tính cho bên thứ ba tra cứu công khai không đăng nhập.
    private function maskName(?string $name): ?string
    {
        if (blank($name)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name));

        if (count($parts) <= 1) {
            return $name;
        }

        $last = array_pop($parts);

        return implode(' ', $parts) . ' ' . mb_substr($last, 0, 1) . str_repeat('*', max(0, mb_strlen($last) - 1));
    }
}
