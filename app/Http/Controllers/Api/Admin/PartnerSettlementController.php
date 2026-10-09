<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Models\PartnerSettlement as Settlement;
use App\Models\PartnerSettlementDispute as Dispute;
use App\Services\OrderCommissionService;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Payment\Entities\Order;

/**
 * Đối soát hoa hồng đối tác Homestay (xem App\Services\SettlementService) — /api/admin/partners/{partner}/settlements/…
 * Đối tác (mọi tài khoản của đối tác) xem; chủ đối tác nộp/khiếu nại; Super Admin sinh, gửi, xử lý khiếu nại, xác nhận chi.
 */
class PartnerSettlementController extends Controller
{
    private const EVIDENCE_RULES = ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'];

    public function __construct(private SettlementService $settlements, private OrderCommissionService $commission) {}

    // GET …/settlements?status=&per_page= — các kỳ đối soát, mới nhất trước. Đối tác không thấy bảng nháp.
    public function index(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        $query = Settlement::query()->where('partner_id', $partner->id)->latest('period_end')->latest('id');
        if (! $request->user()->isSuperAdmin()) {
            $query->where('status', '!=', Settlement::STATUS_DRAFT);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $page = $query->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Settlement $settlement) => $this->settlements->toArray($settlement))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    // GET …/settlements/{id} — bảng đối soát + danh sách đơn (kèm từng khoản giảm và người chịu).
    public function show(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeView($request, $partner);

        return response()->json(['data' => $this->settlements->toArray($this->find($request, $partner, $settlement), withOrders: true)]);
    }

    // POST …/settlements — Super Admin sinh bảng cho 1 kỳ. body: period_start, period_end (YYYY-MM-DD).
    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start']]);

        $settlement = $this->settlements->generate($partner, Carbon::parse($data['period_start'])->startOfDay(), Carbon::parse($data['period_end'])->startOfDay(), $request->user());

        return $settlement
            ? response()->json(['message' => 'Đã sinh bảng đối soát.', 'data' => $this->settlements->toArray($settlement, withOrders: true)], 201)
            : response()->json(['message' => 'Kỳ này không có đơn nào cần đối soát hoặc đã có bảng đối soát.'], 422);
    }

    // POST …/settlements/{id}/send — Super Admin gửi bảng nháp cho đối tác.
    public function send(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);

        return $this->guard(fn () => response()->json(['message' => 'Đã gửi bảng đối soát cho đối tác.', 'data' => $this->settlements->toArray($this->settlements->send($this->find($request, $partner, $settlement), $request->user()))]));
    }

    // POST …/settlements/{id}/payment-link — chủ đối tác lấy QR PayOS của 365home để nộp hoa hồng.
    public function paymentLink(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeRespond($request, $partner);

        return $this->guard(fn () => response()->json(['data' => $this->settlements->toArray($this->settlements->paymentLink($this->find($request, $partner, $settlement)))]));
    }

    // POST …/settlements/{id}/dispute (multipart) — chủ đối tác khiếu nại 1 đơn: order_code, reason, evidence[]?
    public function dispute(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeRespond($request, $partner);
        $data = $request->validate([
            'order_code' => ['required', 'string', 'max:50'],
            'reason'     => ['required', 'string', 'max:2000'],
            'evidence'   => ['sometimes', 'array', 'max:5'],
            'evidence.*' => self::EVIDENCE_RULES,
        ]);

        return $this->guard(function () use ($request, $partner, $settlement, $data) {
            $found = $this->find($request, $partner, $settlement);
            $dispute = $this->settlements->disputeOrder($found, $data['order_code'], $data['reason'], $request->user());
            $this->attach($request, $dispute);

            return response()->json(['message' => 'Đã gửi khiếu nại.', 'data' => $this->settlements->toArray($found->fresh(), withOrders: true)], 201);
        });
    }

    // POST …/settlements/{id}/disputes/{dispute}/resolve — Super Admin: accept (kèm hoa hồng mới của đơn) | reject.
    public function resolveDispute(Request $request, Partner $partner, int $settlement, int $dispute): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate([
            'resolution'          => ['required', 'in:accept,reject'],
            'adjusted_commission' => ['nullable', 'integer', 'min:0', 'required_if:resolution,accept'],
            'note'                => ['required', 'string', 'max:2000'],
        ], ['note.required' => 'Vui lòng ghi kết luận xử lý.']);

        return $this->guard(function () use ($request, $partner, $settlement, $dispute, $data) {
            $found = $this->find($request, $partner, $settlement);
            $record = Dispute::query()->where('settlement_id', $found->id)->findOrFail($dispute);
            $this->settlements->resolveDispute($record, $data['resolution'] === 'accept', isset($data['adjusted_commission']) ? (int) $data['adjusted_commission'] : null, $data['note'], $request->user());

            return response()->json(['message' => 'Đã xử lý khiếu nại.', 'data' => $this->settlements->toArray($found->fresh(), withOrders: true)]);
        });
    }

    // POST …/settlements/{id}/mark-paid — Super Admin ghi nhận đối tác đã chuyển khoản ngoài hệ thống. body: reference
    public function markPaid(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);

        return $this->guard(function () use ($request, $partner, $settlement, $data) {
            $found = $this->find($request, $partner, $settlement);
            if (! $this->settlements->markPaid($found, $data['reference'])) {
                throw new \DomainException('Bảng đối soát này không còn khoản đối tác phải nộp.');
            }

            return response()->json(['message' => 'Đã ghi nhận đối tác đã nộp.', 'data' => $this->settlements->toArray($found->fresh())]);
        });
    }

    // POST …/settlements/{id}/paid-out — Super Admin xác nhận 365home đã chi cho đối tác (net < 0). body: reference
    public function paidOut(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['reference' => ['required', 'string', 'max:255']]);

        return $this->guard(fn () => response()->json(['message' => 'Đã ghi nhận 365home đã chi.', 'data' => $this->settlements->toArray($this->settlements->markPaidOut($this->find($request, $partner, $settlement), $data['reference'], $request->user()))]));
    }

    // GET …/settlements/held-orders — Super Admin: đơn đang bị giữ khoản 365home bù để xem xét.
    public function heldOrders(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);

        $orders = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('subsidy_held_at')->whereNull('settlement_id')->latest('subsidy_held_at')->get();

        return response()->json(['data' => $orders->map(fn (Order $order) => [
            'order_code' => $order->order_code, 'buyer_name' => $order->buyer_name, 'buyer_phone' => $order->buyer_phone,
            'platform_subsidy' => $order->platform_subsidy, 'commission_amount' => $order->commission_amount,
            'reason' => $order->subsidy_held_reason, 'held_at' => $order->subsidy_held_at?->toIso8601String(),
        ])->values()]);
    }

    // POST …/settlements/held-orders/{orderCode}/resolve — Super Admin: release (bù bình thường) | forfeit (không bù khoản này).
    public function resolveHeldOrder(Request $request, Partner $partner, string $orderCode): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['resolution' => ['required', 'in:release,forfeit']]);

        return $this->guard(function () use ($request, $partner, $orderCode, $data) {
            $order = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->where('order_code', $orderCode)->firstOrFail();
            $this->settlements->resolveHeldOrder($order, $data['resolution'] === 'release', $request->user());

            return response()->json(['message' => 'Đã xử lý đơn bị giữ khoản bù. Đơn sẽ vào kỳ đối soát kế tiếp.']);
        });
    }

    // GET …/settlements/{id}/invoice[?format=pdf] — số liệu (hoặc PDF bảng kê) để xuất hoá đơn GTGT phần hoa hồng của kỳ. Super Admin và đối tác (bảng đã gửi).
    public function invoice(Request $request, Partner $partner, int $settlement)
    {
        $this->authorizeView($request, $partner);
        $found = $this->find($request, $partner, $settlement);

        if ($request->query('format') === 'pdf') {
            return response($this->settlements->invoicePdf($found), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="bang-ke-hoa-don-' . $found->code . '.pdf"']);
        }

        return response()->json(['data' => $this->settlements->invoiceData($found)]);
    }

    // POST …/settlements/{id}/invoice — Super Admin ghi nhận đã xuất hoá đơn ngoài hệ thống. body: invoice_no, issued_at? (YYYY-MM-DD)
    public function markInvoiced(Request $request, Partner $partner, int $settlement): JsonResponse
    {
        $this->authorizeSuperAdmin($request, $partner);
        $data = $request->validate(['invoice_no' => ['required', 'string', 'max:50'], 'issued_at' => ['nullable', 'date']]);

        return $this->guard(function () use ($request, $partner, $settlement, $data) {
            $found = $this->settlements->markInvoiced($this->find($request, $partner, $settlement), $data['invoice_no'], $request->user(), isset($data['issued_at']) ? Carbon::parse($data['issued_at']) : null);

            return response()->json(['message' => 'Đã ghi nhận hoá đơn.', 'data' => $this->settlements->invoiceData($found)]);
        });
    }

    private function find(Request $request, Partner $partner, int $id): Settlement
    {
        $query = Settlement::query()->where('partner_id', $partner->id);
        if (! $request->user()->isSuperAdmin()) {
            $query->where('status', '!=', Settlement::STATUS_DRAFT);
        }

        return $query->findOrFail($id);
    }

    private function attach(Request $request, Dispute $dispute): void
    {
        foreach ((array) $request->file('evidence', []) as $file) {
            $dispute->addMedia($file)->toMediaCollection('evidence');
        }
    }

    private function guard(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeRespond(Request $request, Partner $partner): void
    {
        $this->authorizeView($request, $partner);
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->hasRole('partner') || $user->can('update_partner'), 403, 'Chỉ chủ đối tác được thao tác với đối soát.');
    }

    private function authorizeSuperAdmin(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được thực hiện thao tác này.');
    }
}
