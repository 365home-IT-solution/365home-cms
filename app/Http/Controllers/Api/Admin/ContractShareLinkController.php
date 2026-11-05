<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContractShareLink;
use App\Models\ContractShareLinkAccess;
use App\Models\Partner;
use App\Services\ContractShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Link chia sẻ tải hợp đồng (kiểu Google Drive) — cùng service với nút "Chia sẻ link" ở tab Hợp đồng (PartnerResource).
// Super Admin, hoặc chủ đối tác với hợp đồng của chính mình.
class ContractShareLinkController extends Controller
{
    public function __construct(private readonly ContractShareLinkService $links) {}

    // GET /api/admin/partners/{partner}/contract/share-links — mọi link (mới nhất trước), kể cả đã thu hồi/hết hạn.
    public function index(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);

        $links = ContractShareLink::query()->with(['version', 'creator:id,fullname'])->where('partner_id', $partner->id)->latest('id')->get();

        return response()->json(['data' => $links->map(fn (ContractShareLink $link) => $this->links->format($link))->values()]);
    }

    // POST /api/admin/partners/{partner}/contract/share-links — body: expires_in_days? (0|1|7|30|90, mặc định 0 = không hết hạn), password?, max_downloads?
    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizePartner($request, $partner);
        $data = $request->validate([
            'expires_in_days' => ['nullable', 'integer', Rule::in(array_keys(ContractShareLinkService::EXPIRY_OPTIONS))],
            'password' => ['nullable', 'string', 'min:4', 'max:100'],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ], [], ['expires_in_days' => 'thời hạn link', 'password' => 'mật khẩu', 'max_downloads' => 'số lượt tải tối đa']);

        $result = $this->links->create($partner, $request->user(), $data);

        return response()->json(['message' => 'Đã tạo link chia sẻ hợp đồng.', 'data' => $this->links->format($result['link']->load(['version', 'creator:id,fullname']))], 201);
    }

    // GET /api/admin/partners/{partner}/contract/share-links/{link}/accesses — nhật ký mở/tải link (100 lượt gần nhất).
    public function accesses(Request $request, Partner $partner, ContractShareLink $link): JsonResponse
    {
        $this->authorizeLink($request, $partner, $link);

        return response()->json(['data' => $link->accesses()->limit(100)->get()->map(fn (ContractShareLinkAccess $access) => [
            'action' => $access->action,
            'action_label' => ContractShareLinkAccess::ACTIONS[$access->action] ?? $access->action,
            'ip' => $access->ip,
            'user_agent' => $access->user_agent,
            'created_at' => $access->created_at?->toIso8601String(),
        ])->values()]);
    }

    // DELETE /api/admin/partners/{partner}/contract/share-links/{link} — thu hồi link (link ngừng hoạt động ngay, vẫn giữ lại lịch sử).
    public function destroy(Request $request, Partner $partner, ContractShareLink $link): JsonResponse
    {
        $this->authorizeLink($request, $partner, $link);
        $this->links->revoke($link, $request->user());

        return response()->json(['message' => 'Đã thu hồi link chia sẻ.', 'data' => $this->links->format($link->fresh(['version', 'creator:id,fullname']))]);
    }

    private function authorizePartner(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner(), 404);
        abort_unless($partner->usesContract(), 404, 'MiniHouse không dùng hợp đồng đối tác (mua gói để sử dụng).');
        abort_unless($this->links->canManage($request->user(), $partner), 403, 'Bạn không có quyền chia sẻ hợp đồng của đối tác này.');
    }

    private function authorizeLink(Request $request, Partner $partner, ContractShareLink $link): void
    {
        $this->authorizePartner($request, $partner);
        abort_unless($link->partner_id === $partner->id, 404);
    }
}
