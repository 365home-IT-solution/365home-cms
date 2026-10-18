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

    public function __construct(private SettlementService $settlements, private OrderCommissionService $commission, private \App\Services\CommissionRepriceService $reprice) {}

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
        $data = $request->validate([
            'period_start'   => ['required', 'date'],
            'period_end'     => ['required', 'date', 'after_or_equal:period_start'],
            // true (mặc định) = chỉ lấy đơn chốt TRONG kỳ; false = gom cả đơn của kỳ trước chưa đối soát (như lệnh tự động).
            'only_in_period' => ['nullable', 'boolean'],
            // true = sinh bảng CẢ KỲ, gộp các bảng đã có nằm trong khoảng ngày (vd đã có 01/10–15/10, gửi 01/10–31/10); xem SettlementService::generateMerged().
            // merge_sent = cho phép gộp cả bảng đã gửi đối tác (mặc định false).
            'merge_existing' => ['nullable', 'boolean'],
            'merge_sent'     => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('merge_existing')) {
            return $this->guard(fn () => response()->json([
                'message' => 'Đã sinh bảng đối soát cả kỳ (gộp bảng cũ).',
                'data'    => $this->settlements->toArray(
                    $this->settlements->generateMerged($partner, Carbon::parse($data['period_start'])->startOfDay(), Carbon::parse($data['period_end'])->startOfDay(), $request->user(), $request->boolean('merge_sent')),
                    withOrders: true,
                ),
            ], 201));
        }

        $settlement = $this->settlements->generate(
            $partner,
            Carbon::parse($data['period_start'])->startOfDay(),
            Carbon::parse($data['period_end'])->startOfDay(),
            $request->user(),
            (bool) ($data['only_in_period'] ?? true),
        );

        return $settlement
            ? response()->json(['message' => 'Đã sinh bảng đối soát.', 'data' => $this->settlements->toArray($settlement, withOrders: true)], 201)
            : response()->json(['message' => $this->settlements->whyNoSettlement($partner, Carbon::parse($data['period_start'])->startOfDay(), Carbon::parse($data['period_end'])->startOfDay())], 422);
    }

    // POST …/settlements/commission-reprice/preview — Super Admin XEM TRƯỚC việc áp lại tỷ lệ hoa hồng cho đơn đã phát sinh (không ghi gì).
    // body: period_start, period_end (YYYY-MM-DD), rate (0–100), include_sent?, with_orders? — xem App\Services\CommissionRepriceService.
    public function repricePreview(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeReprice($request, $partner);
        $data = $this->validateReprice($request);

        $plan = $this->reprice->plan($partner, Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), (float) $data['rate'], (bool) ($data['include_sent'] ?? false));

        return response()->json(['data' => $this->repriceHeader($partner, $data) + $this->reprice->summary($plan, (bool) ($data['with_orders'] ?? false))]);
    }

    // POST …/settlements/commission-reprice — Super Admin ÁP DỤNG. Như preview, thêm: confirm (phải true), update_partner_rate? (mặc định true:
    // đổi luôn tỷ lệ trong hồ sơ đối tác để đơn tạo sau cũng theo tỷ lệ mới).
    public function reprice(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeReprice($request, $partner);
        $data = $this->validateReprice($request, applying: true);

        $result = $this->reprice->apply(
            $partner,
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end']),
            (float) $data['rate'],
            (bool) ($data['include_sent'] ?? false),
            (bool) ($data['update_partner_rate'] ?? true),
        );

        return response()->json([
            'message' => $result['orders'] > 0 ? "Đã áp lại hoa hồng {$data['rate']}% cho {$result['orders']} đơn." : 'Không có đơn nào cần sửa trong phạm vi đã chọn.',
            'data'    => $this->repriceHeader($partner->fresh(), $data) + [
                'orders_updated'    => $result['orders'],
                'commission_before' => $result['commission_before'],
                'commission_after'  => $result['commission_after'],
                'difference'        => $result['commission_after'] - $result['commission_before'],
                'settlements'       => $result['settlements'],
            ],
        ]);
    }

    private function authorizeReprice(Request $request, Partner $partner): void
    {
        $this->authorizeSuperAdmin($request, $partner);
        abort_if($partner->isPlatformPartner(), 422, 'Đối tác nền tảng (365home tự bán) không có hoa hồng.');
    }

    /** @return array<string, mixed> */
    private function validateReprice(Request $request, bool $applying = false): array
    {
        $data = $request->validate([
            'period_start'        => ['required', 'date'],
            'period_end'          => ['required', 'date', 'after_or_equal:period_start'],
            'rate'                => ['required', 'numeric', 'min:0', 'max:100'],
            'include_sent'        => ['nullable', 'boolean'],
            'with_orders'         => ['nullable', 'boolean'],
            'update_partner_rate' => ['nullable', 'boolean'],
            'confirm'             => $applying ? ['required', 'accepted'] : ['nullable', 'boolean'],
        ]);

        abort_if(Carbon::parse($data['period_start'])->diffInDays(Carbon::parse($data['period_end'])) > 366, 422, 'Khoảng ngày tối đa 366 ngày.');

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function repriceHeader(Partner $partner, array $data): array
    {
        return [
            'partner'      => ['id' => $partner->id, 'name' => $partner->name, 'commission_rate' => $partner->commission_rate],
            'period_start' => Carbon::parse($data['period_start'])->toDateString(),
            'period_end'   => Carbon::parse($data['period_end'])->toDateString(),
            'rate'         => (float) $data['rate'],
            'include_sent' => (bool) ($data['include_sent'] ?? false),
        ];
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

    // GET …/settlements/{id}/export — file Excel của 1 bảng đối soát (sheet Tổng hợp + Chi tiết đơn). Super Admin và đối tác (bảng đã gửi).
    public function export(Request $request, Partner $partner, int $settlement)
    {
        $this->authorizeView($request, $partner);
        $found = $this->find($request, $partner, $settlement);

        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\PartnerSettlementExport($found), \App\Exports\PartnerSettlementExport::fileName($found));
    }

    // GET /api/admin/settlements/cycle/export[?period_start=&period_end=&partner_ids=] — BẢNG ĐỐI SOÁT CỦA TẤT CẢ ĐỐI TÁC TRONG 1 CHU KỲ
    // (chỉ Super Admin): 1 sheet, mỗi đối tác có bảng trong kỳ 1 dòng. Bỏ trống kỳ = kỳ 2 lần/tháng vừa đóng gần nhất.
    public function exportCycle(Request $request, SettlementService $service)
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được xuất đối soát của tất cả đối tác.');

        $data = $request->validate([
            'period_start' => ['nullable', 'date', 'required_with:period_end'],
            'period_end'   => ['nullable', 'date', 'required_with:period_start', 'after_or_equal:period_start'],
            'partner_ids'  => ['nullable', 'string'],
        ]);

        [$start, $end] = isset($data['period_start'])
            ? [Carbon::parse($data['period_start']), Carbon::parse($data['period_end'])]
            : $service->lastClosedPeriod('biweekly', now());

        $ids = collect(explode(',', (string) ($data['partner_ids'] ?? '')))->map(fn ($v) => trim($v))->filter()->values()->all();

        return \Maatwebsite\Excel\Facades\Excel::download(
            \App\Exports\CycleSettlementExport::for($request->user(), $start, $end, ['partner_ids' => $ids]),
            \App\Exports\CycleSettlementExport::fileName($start, $end)
        );
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
