<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerRefundClaim as Claim;
use App\Services\RefundClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Payment\Entities\Order;

/**
 * Yêu cầu hoàn tiền khách của đơn có tiền đang ở đối tác (xem App\Services\RefundClaimService): 365home (Super Admin hoặc nhân viên đối tác nền tảng) mở yêu cầu,
 * đối tác có 24 giờ để hoàn; quá hạn thì Super Admin hoàn thay và hệ thống tự lập đề xuất trừ ký quỹ.
 */
class PartnerRefundClaimController extends Controller
{
    public function __construct(private RefundClaimService $claims) {}

    // POST /api/admin/orders/{order_code}/refund-claims — body: amount, reason
    public function store(Request $request, string $orderCode): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->belongsToPlatformPartner(), 403, 'Chỉ 365home được tạo yêu cầu hoàn tiền thay cho khách.');
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);

        $order = Order::withoutGlobalScopes()->where('order_code', $orderCode)->first();
        abort_unless($order, 404, 'Không tìm thấy đơn.');

        return $this->guard(fn () => response()->json(['message' => 'Đã tạo yêu cầu hoàn tiền, đã báo đối tác.', 'data' => $this->claims->open($order, (int) $data['amount'], $data['reason'], $user)->toApi()], 201));
    }

    // GET …/refund-claims?status= — Super Admin và mọi tài khoản của đối tác
    public function index(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        $query = Claim::query()->where('partner_id', $partner->id)->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['data' => $query->limit(200)->get()->map->toApi()->values()]);
    }

    // POST …/refund-claims/{claim}/refund-on-behalf — Super Admin hoàn thay sau khi quá hạn. body: method = cash | transfer
    public function refundOnBehalf(Request $request, Partner $partner, int $claim): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['method' => ['required', 'in:cash,transfer']]);

        return $this->guard(fn () => response()->json(['message' => 'Đã ghi nhận 365home hoàn thay và lập đề xuất trừ ký quỹ.', 'data' => $this->claims->refundOnBehalf($this->find($partner, $claim), $data['method'], $request->user())->toApi()]));
    }

    // POST …/refund-claims/{claim}/cancel — Super Admin huỷ yêu cầu (vd khách rút lại)
    public function cancel(Request $request, Partner $partner, int $claim): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);

        return $this->guard(fn () => response()->json(['message' => 'Đã huỷ yêu cầu hoàn tiền.', 'data' => $this->claims->cancel($this->find($partner, $claim), $request->user())->toApi()]));
    }

    private function find(Partner $partner, int $id): Claim
    {
        return Claim::query()->where('partner_id', $partner->id)->findOrFail($id);
    }

    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (\DomainException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeSuperAdmin(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
