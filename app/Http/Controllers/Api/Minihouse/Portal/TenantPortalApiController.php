<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\TenantPortalService;
use Modules\Minihouse\Http\Controllers\Portal\Concerns\InteractsWithTenantPortalData;

// Bản API (token Sanctum, cho app di động/bên thứ 3) của
// Modules\Minihouse\Http\Controllers\Portal\TenantPortalController (web, session) — dùng CHUNG
// InteractsWithTenantPortalData/TenantPortalService nên KHÔNG được phép trả lời khác web (đúng lớp
// lỗi lệch dữ liệu đã gặp nhiều lần trong module này). $request->user() ở MỌI method dưới đây LUÔN
// là Tenant — đảm bảo bởi middleware 'tenant.api' (TenantApiAuth) đứng trước route.
class TenantPortalApiController extends Controller
{
    use InteractsWithTenantPortalData;

    // GET /api/minihouse/portal/dashboard
    public function dashboard(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        $contracts = TenantPortalService::tenantContracts($tenant);
        $activeContract = $contracts->firstWhere('status', Contract::STATUS_ACTIVE);
        $building = $activeContract?->room?->building;

        return response()->json([
            'data' => [
                'tenant' => ['id' => $tenant->id, 'fullname' => $tenant->fullname, 'phone' => $tenant->phone],
                'active_contract' => $activeContract ? [
                    'id'             => $activeContract->id,
                    'room_code'      => $activeContract->room?->code,
                    'building_name'  => $building?->name,
                    'monthly_price'  => (float) $activeContract->monthly_price,
                    'start_date'     => $activeContract->start_date?->toDateString(),
                    'end_date'       => $activeContract->end_date?->toDateString(),
                ] : null,
                'unpaid_total'         => TenantPortalService::unpaidTotalForActiveContract($activeContract),
                'unpaid_invoice_count' => TenantPortalService::unpaidInvoiceCount($contracts),
                'unread_notification_count' => PortalNotification::where('tenant_id', $tenant->id)->whereNull('read_at')->count(),
                'owner' => $building ? [
                    'name'    => $building->owner_name,
                    'phone'   => $building->owner_phone,
                    'address' => $building->address,
                ] : null,
            ],
        ]);
    }

    // GET /api/minihouse/portal/notifications — cùng hành vi web: liệt kê xong tự đánh dấu ĐÃ ĐỌC.
    public function notifications(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        $notifications = PortalNotification::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        PortalNotification::where('tenant_id', $tenant->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'data' => collect($notifications->items())->map(fn (PortalNotification $n) => [
                'id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body,
                'link' => $n->link, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String(),
            ]),
            'meta' => $this->paginationMeta($notifications),
        ]);
    }

    // GET /api/minihouse/portal/invoices
    public function invoices(Request $request): JsonResponse
    {
        $invoices = $this->invoicesQuery($this->tenant($request))->paginate((int) $request->integer('per_page', 12));

        return response()->json([
            'data' => collect($invoices->items())->map(fn (Invoice $i) => $this->invoiceSummary($i)),
            'meta' => $this->paginationMeta($invoices),
        ]);
    }

    // GET /api/minihouse/portal/invoices/{id}
    public function showInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = $this->findOwnedInvoice($this->tenant($request), $id);

        return response()->json(['data' => $this->invoiceDetail($invoice)]);
    }

    // POST /api/minihouse/portal/invoices/{id}/pay
    public function payInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = $this->findOwnedInvoiceForPayment($this->tenant($request), $id);

        if ($invoice->remainingAmount() <= 0) {
            return response()->json(['message' => 'Hoá đơn này đã được thanh toán đủ.'], 409);
        }

        // returnUrl trỏ về route WEB của Portal (không phải route API) — sau khi thanh toán xong
        // trên trang cổng thanh toán (trình duyệt trong app/WebView), khách cần 1 TRANG XEM ĐƯỢC để
        // quay lại, không phải 1 JSON endpoint. App di động tự làm mới lại dữ liệu qua API sau đó.
        $returnUrl = route('minihouse.portal.invoices.show', $invoice->id);

        try {
            $payment = $this->createPayment($invoice, $returnUrl, $request->ip());
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $payment) {
            return response()->json(['message' => 'Chủ nhà chưa cấu hình thanh toán trực tuyến cho toà nhà này — vui lòng liên hệ trực tiếp để thanh toán.'], 422);
        }

        return response()->json(['data' => $payment]);
    }

    // GET /api/minihouse/portal/payments
    public function payments(Request $request): JsonResponse
    {
        $payments = $this->paymentsQuery($this->tenant($request))->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($payments->items())->map(fn (InvoicePayment $p) => [
                'id' => $p->id, 'amount' => (float) $p->amount, 'paid_at' => $p->paid_at?->toDateString(),
                'payment_method' => $p->payment_method, 'status' => $p->status, 'note' => $p->note,
                'invoice_id' => $p->invoice_id, 'invoice_month' => $p->invoice?->month?->format('m/Y'),
            ]),
            'meta' => $this->paginationMeta($payments),
        ]);
    }

    // GET /api/minihouse/portal/contracts
    public function contracts(Request $request): JsonResponse
    {
        $contracts = TenantPortalService::tenantContracts($this->tenant($request))->sortByDesc('start_date')->values();

        return response()->json(['data' => $contracts->map(fn (Contract $c) => $this->contractSummary($c))]);
    }

    // GET /api/minihouse/portal/contracts/{id}
    public function showContract(Request $request, int $id): JsonResponse
    {
        $contract = TenantPortalService::tenantContracts($this->tenant($request))->firstWhere('id', $id);

        abort_unless($contract, 403);

        $files = [
            'contract_file'          => 'Hợp đồng (file)',
            'handover_file'          => 'Biên bản bàn giao (lúc nhận)',
            'deposit_receipt_file'   => 'Biên bản đặt cọc',
            'checkout_handover_file' => 'Biên bản bàn giao (lúc trả phòng)',
        ];

        return response()->json(['data' => array_merge($this->contractSummary($contract), [
            'deposit_amount'           => (float) $contract->deposit_amount,
            'checkout_at'              => $contract->checkout_at?->toDateString(),
            'deposit_refunded_amount'  => $contract->deposit_refunded_amount !== null ? (float) $contract->deposit_refunded_amount : null,
            'contract_content'         => $contract->contract_content,
            'files' => collect($files)->mapWithKeys(fn ($label, $field) => [
                $field => $contract->{$field} ? ['label' => $label, 'url' => Storage::disk('public')->url($contract->{$field})] : null,
            ])->filter()->values(),
        ])]);
    }

    // POST /api/minihouse/portal/feedback
    public function storeFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rating'  => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['nullable', 'string', 'max:2000'],
        ]);

        $feedback = $this->createFeedback($this->tenant($request), $data);

        return response()->json(['data' => ['id' => $feedback->id]], 201);
    }

    // POST /api/minihouse/portal/password — xem giải thích ĐẦY ĐỦ tại sao KHÔNG bắt nhập mật khẩu cũ
    // ở Modules\Minihouse\Http\Controllers\Portal\TenantPortalController::updatePassword() gốc.
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $this->tenant($request)->update(['password' => $data['password']]);

        return response()->json(['message' => 'Đã đặt mật khẩu — lần sau bạn có thể đăng nhập bằng SĐT + mật khẩu, không cần chờ mã OTP nữa.']);
    }

    private function tenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        return $tenant;
    }

    private function invoiceSummary(Invoice $invoice): array
    {
        return [
            'id'              => $invoice->id,
            'month'           => $invoice->month?->format('m/Y'),
            'room_code'       => $invoice->contract?->room?->code,
            'total_amount'    => (float) $invoice->total_amount,
            'amount_paid'     => (float) $invoice->amount_paid,
            'remaining'       => $invoice->remainingAmount(),
            'status'          => $invoice->status,
        ];
    }

    private function invoiceDetail(Invoice $invoice): array
    {
        return array_merge($this->invoiceSummary($invoice), [
            'building_name'   => $invoice->contract?->room?->building?->name,
            'room_price'      => (float) $invoice->room_price,
            'electric_amount' => (float) $invoice->electric_amount,
            'electric_start'  => $invoice->electric_start,
            'electric_end'    => $invoice->electric_end,
            'water_amount'    => (float) $invoice->water_amount,
            'water_start'     => $invoice->water_start,
            'water_end'       => $invoice->water_end,
            'items'           => $invoice->items->map(fn ($item) => ['name' => $item->name, 'amount' => (float) $item->amount]),
        ]);
    }

    private function contractSummary(Contract $contract): array
    {
        return [
            'id'            => $contract->id,
            'status'        => $contract->status,
            'room_code'     => $contract->room?->code,
            'building_name' => $contract->room?->building?->name,
            'monthly_price' => (float) $contract->monthly_price,
            'start_date'    => $contract->start_date?->toDateString(),
            'end_date'      => $contract->end_date?->toDateString(),
        ];
    }

    private function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }
}
