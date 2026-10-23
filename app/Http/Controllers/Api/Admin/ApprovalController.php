<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApprovalException;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Duyệt trước khi đăng (xem App\Services\ApprovalService): đối tác gửi bảng giá / phòng, 365home (Super Admin) duyệt hoặc từ chối.
 *
 *  - GET  /api/admin/approvals            danh sách (đối tác thấy của mình; Super Admin thấy tất cả, lọc theo X-Partner-Id / partner_id)
 *  - GET  /api/admin/approvals/summary    số yêu cầu theo trạng thái (cho badge)
 *  - GET  /api/admin/approvals/{id}       chi tiết kèm danh sách thay đổi cũ → mới
 *  - POST /api/admin/approvals/{id}/approve   Super Admin duyệt (note tuỳ chọn)
 *  - POST /api/admin/approvals/{id}/reject    Super Admin từ chối (reason bắt buộc)
 *  - POST /api/admin/approvals/{id}/cancel    đối tác rút lại yêu cầu đang chờ
 *  - POST /api/admin/approvals/{id}/resubmit  đối tác gửi lại yêu cầu bị từ chối / đã rút lại
 */
class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'     => 'sometimes|nullable|in:pending,approved,rejected,cancelled',
            'kind'       => 'sometimes|nullable|in:' . implode(',', array_keys(ApprovalRequest::KINDS)),
            'partner_id' => 'sometimes|nullable|uuid',
            'search'     => 'sometimes|nullable|string|max:100',
            'per_page'   => 'sometimes|integer|min:1|max:100',
        ]);

        $user  = $request->user();
        $query = $this->approvals->visibleTo($user)->with(['partner:id,name,legal_name', 'requester:id,fullname', 'reviewer:id,fullname'])->latest('id');

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['kind'])) {
            $query->where('kind', $data['kind']);
        }
        if (! empty($data['partner_id']) && $this->approvals->isPlatformSide($user)) {
            $query->where('partner_id', $data['partner_id']);
        }
        if (! empty($data['search'])) {
            $query->where('subject_label', 'like', '%' . $data['search'] . '%');
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ApprovalRequest $r) => $this->approvals->present($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user   = $request->user();
        $counts = $this->approvals->visibleTo($user)->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => [
            'pending'    => (int) ($counts[ApprovalRequest::STATUS_PENDING] ?? 0),
            'approved'   => (int) ($counts[ApprovalRequest::STATUS_APPROVED] ?? 0),
            'rejected'   => (int) ($counts[ApprovalRequest::STATUS_REJECTED] ?? 0),
            'cancelled'  => (int) ($counts[ApprovalRequest::STATUS_CANCELLED] ?? 0),
            'can_review' => $this->approvals->canReview($user),
            'requires_approval' => $this->approvals->requiresApproval($user),
        ]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->approvals->present($this->find($request, $id), true)]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $this->authorizeReview($request);
        $data = $request->validate(['note' => 'nullable|string|max:1000']);
        $req  = $this->find($request, $id);

        return $this->guard(fn () => response()->json([
            'message' => 'Đã duyệt.',
            'data'    => $this->approvals->present($this->approvals->approve($req, $request->user(), $data['note'] ?? null), true),
        ]));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $this->authorizeReview($request);
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $req  = $this->find($request, $id);

        return $this->guard(fn () => response()->json([
            'message' => 'Đã từ chối.',
            'data'    => $this->approvals->present($this->approvals->reject($req, $request->user(), $data['reason']), true),
        ]));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $req = $this->find($request, $id);

        return $this->guard(fn () => response()->json([
            'message' => 'Đã rút lại yêu cầu.',
            'data'    => $this->approvals->present($this->approvals->cancel($req, $request->user()), true),
        ]));
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $req = $this->find($request, $id);

        return $this->guard(fn () => response()->json([
            'message' => 'Đã gửi lại 365home duyệt.',
            'data'    => $this->approvals->present($this->approvals->resubmit($req, $request->user()), true),
        ], 201));
    }

    private function find(Request $request, int $id): ApprovalRequest
    {
        /** @var User $user */
        $user = $request->user();

        $found = $this->approvals->visibleTo($user)->find($id);
        abort_unless($found, 404, 'Không tìm thấy yêu cầu duyệt.');

        return $found;
    }

    private function authorizeReview(Request $request): void
    {
        abort_unless($this->approvals->canReview($request->user()), 403, 'Chỉ Super Admin được duyệt / từ chối.');
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ApprovalException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
