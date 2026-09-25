<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use App\Services\PdfSigning\ContractPdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\ResidenceDeclaration;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TenantFeedback;
use Modules\Minihouse\App\Models\TenantPushToken;
use Modules\Minihouse\App\Services\ContractContentRenderer;
use Modules\Minihouse\App\Services\InvoiceContentRenderer;
use Modules\Minihouse\App\Services\TenantPortalService;
use Modules\Minihouse\Http\Controllers\Portal\Concerns\InteractsWithTenantPortalData;
use Symfony\Component\HttpFoundation\Response;

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
                'unpaid_total'         => TenantPortalService::unpaidTotalForContracts($contracts),
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

    // GET /api/minihouse/portal/notifications — mirror ĐÚNG App\Http\Controllers\Api\
    // NotificationController::index() (Home): CHỈ liệt kê, KHÔNG tự đánh dấu đã đọc (trước đây tự
    // đánh dấu HẾT ngay khi gọi — khác Home, khiến app không phân biệt được thông báo nào khách THẬT
    // SỰ đã xem). Đánh dấu đọc giờ phải gọi rõ ràng qua markRead()/markAllRead() bên dưới.
    public function notifications(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        $notifications = PortalNotification::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        $data = collect($notifications->items())->map(fn (PortalNotification $n) => array_merge([
            'id'       => $n->id,
            'type'     => $n->type,
            'title'    => $n->title,
            'body'     => $n->body,
            'link'     => $n->link,
            'is_read'  => $n->read_at !== null,
            'read_at'  => $n->read_at?->toIso8601String(),
            'sent_at'  => $n->created_at->toIso8601String(),
        ], $n->data ?? []))->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $notifications->currentPage(),
            'last_page'    => $notifications->lastPage(),
            'total'        => $notifications->total(),
            'unread_count' => PortalNotification::where('tenant_id', $tenant->id)->whereNull('read_at')->count(),
        ]);
    }

    // POST /api/minihouse/portal/notifications/{id}/read — mirror POST /api/notifications/{id}/read (Home).
    public function markNotificationRead(Request $request, int $id): JsonResponse
    {
        $notification = PortalNotification::where('tenant_id', $this->tenant($request)->id)->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'id'      => $notification->id,
            'is_read' => true,
            'read_at' => $notification->read_at->toIso8601String(),
        ]);
    }

    // POST /api/minihouse/portal/notifications/read-all — mirror POST /api/notifications/read-all (Home).
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $updated = PortalNotification::where('tenant_id', $this->tenant($request)->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "Đã đánh dấu đã xem {$updated} thông báo.",
            'updated' => $updated,
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

        $room = $contract->room;

        return response()->json(['data' => array_merge($this->contractSummary($contract), [
            'deposit_amount'           => (float) $contract->deposit_amount,
            'checkout_at'              => $contract->checkout_at?->toDateString(),
            'deposit_refunded_amount'  => $contract->deposit_refunded_amount !== null ? (float) $contract->deposit_refunded_amount : null,
            'contract_content'         => $contract->contract_content,
            'files' => collect($files)->mapWithKeys(fn ($label, $field) => [
                $field => $contract->{$field} ? ['label' => $label, 'url' => Storage::disk('public')->url($contract->{$field})] : null,
            ])->filter()->values(),
            // Phòng đang thuê — ảnh/tiện ích/tài sản trong phòng, để app hiện được ngay trong màn
            // "Hợp đồng của tôi" thay vì khách phải tự nhớ đã có gì trong phòng.
            'room' => $room ? [
                'photos'    => $room->photos ?? [],
                'amenities' => $room->amenities->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'image' => $a->image]),
                'assets'    => $room->assets->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'condition' => $a->condition, 'note' => $a->note]),
            ] : null,
            // Lịch sử gia hạn (nhân viên tạo qua ContractController::renew()) — khách xem lại giá/ngày
            // đã thay đổi qua từng lần gia hạn.
            'renewals' => $contract->renewals()->orderByDesc('created_at')->get()->map(fn ($r) => [
                'old_end_date'      => $r->old_end_date?->toDateString(),
                'new_end_date'      => $r->new_end_date?->toDateString(),
                'old_monthly_price' => (float) $r->old_monthly_price,
                'new_monthly_price' => (float) $r->new_monthly_price,
                'note'              => $r->note,
                'created_at'        => $r->created_at->toIso8601String(),
            ]),
            // Khách tự xem đã được khai báo lưu trú (Bộ Công an) hay chưa — CHỈ trạng thái, không
            // lộ toàn bộ hồ sơ (CCCD, quê quán...) đã khai.
            'residence_declaration' => ($declaration = ResidenceDeclaration::where('contract_id', $contract->id)
                ->where('tenant_id', $this->tenant($request)->id)->first())
                ? ['is_declared' => $declaration->isDeclared(), 'declared_at' => $declaration->declared_at?->toIso8601String()]
                : null,
            // Cờ khách đã gửi yêu cầu gia hạn/trả phòng trước đó chưa (để app hiện "Đã gửi yêu cầu,
            // đang chờ nhân viên liên hệ" thay vì lại hiện nút gửi tiếp).
            'renewal_requested_at'  => $contract->renewal_requested_at?->toIso8601String(),
            'checkout_requested_at' => $contract->checkout_requested_at?->toIso8601String(),
        ])]);
    }

    // POST /api/minihouse/portal/contracts/{id}/renewal-request — CHỈ đánh dấu + ghi chú, KHÔNG tự
    // gia hạn — nhân viên thấy cờ này trên panel rồi tự liên hệ, gọi ContractController::renew()
    // như bình thường khi chốt xong điều khoản mới.
    public function requestRenewal(Request $request, int $id): JsonResponse
    {
        $contract = TenantPortalService::tenantContracts($this->tenant($request))->firstWhere('id', $id);
        abort_unless($contract, 403);

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ gửi được yêu cầu cho hợp đồng đang hiệu lực.'], 422);
        }

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $contract->update([
            'renewal_requested_at' => now(),
            'renewal_request_note' => $data['note'] ?? null,
        ]);

        return response()->json(['message' => 'Đã gửi yêu cầu gia hạn. Nhân viên sẽ liên hệ lại với bạn.']);
    }

    // POST /api/minihouse/portal/contracts/{id}/checkout-request — cùng nguyên tắc trên, cho trả
    // phòng sớm/không tiếp tục thuê.
    public function requestCheckout(Request $request, int $id): JsonResponse
    {
        $contract = TenantPortalService::tenantContracts($this->tenant($request))->firstWhere('id', $id);
        abort_unless($contract, 403);

        if ($contract->status !== Contract::STATUS_ACTIVE) {
            return response()->json(['message' => 'Chỉ gửi được yêu cầu cho hợp đồng đang hiệu lực.'], 422);
        }

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $contract->update([
            'checkout_requested_at' => now(),
            'checkout_request_note' => $data['note'] ?? null,
        ]);

        return response()->json(['message' => 'Đã gửi yêu cầu trả phòng. Nhân viên sẽ liên hệ lại với bạn.']);
    }

    // GET /api/minihouse/portal/contracts/{id}/pdf — bản PDF hợp đồng ĐANG LƯU (contract_content),
    // dùng CHUNG renderer với ContractPrintController (nhân viên) — KHÔNG thể tái dùng thẳng
    // controller đó vì nó xác thực theo guard "web" (App\Models\User), ở đây guard "tenant".
    public function contractPdf(Request $request, int $id): Response
    {
        $contract = TenantPortalService::tenantContracts($this->tenant($request))->firstWhere('id', $id);
        abort_unless($contract, 403);

        $pdf = ContractPdfRenderer::render(ContractContentRenderer::renderPrintable($contract));

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"hop-dong-{$contract->id}.pdf\"",
        ]);
    }

    // GET /api/minihouse/portal/invoices/{id}/pdf — phiếu thu PDF, KHÔNG kèm QR thanh toán (khách
    // dùng nút "Thanh toán" (payInvoice()) riêng để lấy QR/link cổng thanh toán mới nhất — nhúng lại
    // QR ở đây dễ lệch với link thật đang hiệu lực). Chỉ để in/lưu làm chứng từ.
    public function invoicePdf(Request $request, int $id): Response
    {
        $invoice = $this->findOwnedInvoice($this->tenant($request), $id);

        $pdf = ContractPdfRenderer::render(InvoiceContentRenderer::renderPrintable($invoice, null));

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"phieu-thu-hoa-don-{$invoice->id}.pdf\"",
        ]);
    }

    // GET /api/minihouse/portal/feedback — lịch sử phản hồi CHÍNH khách này đã gửi (storeFeedback()
    // chỉ tạo mới, trước đây không có cách nào xem lại đã gửi gì/khi nào).
    public function feedback(Request $request): JsonResponse
    {
        $feedbacks = TenantFeedback::where('tenant_id', $this->tenant($request)->id)
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json([
            'data' => collect($feedbacks->items())->map(fn (TenantFeedback $f) => [
                'id' => $f->id, 'rating' => $f->rating, 'content' => $f->content,
                'created_at' => $f->created_at->toIso8601String(),
            ]),
            'meta' => $this->paginationMeta($feedbacks),
        ]);
    }

    // GET /api/minihouse/portal/notifications/unread-count — đếm NHANH, KHÔNG kèm đánh dấu đã đọc
    // (khác notifications() ở trên, tự mark-all-read khi liệt kê) — dùng để hiện chấm đỏ badge trên
    // icon chuông mà KHÔNG làm mất trạng thái "chưa đọc" trước khi khách thực sự mở ra xem.
    public function unreadNotificationCount(Request $request): JsonResponse
    {
        $count = PortalNotification::where('tenant_id', $this->tenant($request)->id)->whereNull('read_at')->count();

        return response()->json(['data' => ['unread_count' => $count]]);
    }

    // GET /api/minihouse/portal/profile
    public function profile(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);

        return response()->json(['data' => [
            'id'                       => $tenant->id,
            'fullname'                 => $tenant->fullname,
            'phone'                    => $tenant->phone,
            'date_of_birth'            => $tenant->date_of_birth?->toDateString(),
            'gender'                   => $tenant->gender,
            'nationality'              => $tenant->nationality,
            'id_card_number'           => $tenant->id_card_number,
            'hometown'                 => $tenant->hometown,
            'permanent_address'        => $tenant->permanent_address,
            'occupation'               => $tenant->occupation,
            'workplace'                => $tenant->workplace,
            'emergency_contact_name'   => $tenant->emergency_contact_name,
            'emergency_contact_phone'  => $tenant->emergency_contact_phone,
        ]]);
    }

    // PUT /api/minihouse/portal/profile — CHỈ cho tự sửa "liên hệ khẩn cấp", KHÔNG cho sửa CCCD/họ
    // tên/SĐT (thông tin định danh pháp lý — phải do nhân viên xác minh giấy tờ rồi mới sửa qua
    // panel, tự ý cho khách đổi sẽ sai lệch hồ sơ khai báo lưu trú/hợp đồng đã ký).
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emergency_contact_name'  => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        $this->tenant($request)->update($data);

        return response()->json(['message' => 'Đã cập nhật thông tin.']);
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

    // POST /api/minihouse/portal/push-token — đăng ký thiết bị nhận Thông báo đẩy (Web Push/FCM hoặc
    // Expo, xem App\Services\FcmService::sendToTenant()). updateOrCreate theo đúng "token" (KHÔNG
    // theo tenant_id) — 1 thiết bị/trình duyệt chỉ có 1 token vật lý, nếu khách khác đăng nhập trên
    // CÙNG thiết bị đó thì token phải chuyển sang thuộc khách mới, không giữ lại của khách cũ.
    public function registerPushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string', 'max:1000'],
            'platform' => ['nullable', 'string', 'max:50'],
        ]);

        // Validate qua Firebase TRƯỚC khi lưu — mirror App\Http\Controllers\Api\DeviceTokenController
        // (Home). Dùng CHUNG App\Services\FcmService::validateToken() (không phụ thuộc Customer hay
        // Tenant, chỉ kiểm tra token thật với Firebase/định dạng Expo) — cùng cờ bypass
        // app.fcm_bypass_enabled cho dev/test.
        if (! config('app.fcm_bypass_enabled', false) && ! app(\App\Services\FcmService::class)->validateToken($data['token'])) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc không được Firebase xác nhận.',
                'errors'  => ['token' => ['Token không hợp lệ.']],
            ], 422);
        }

        TenantPushToken::updateOrCreate(
            ['token' => $data['token']],
            ['tenant_id' => $this->tenant($request)->id, 'platform' => $data['platform'] ?? null],
        );

        return response()->json(['message' => 'Đã đăng ký nhận thông báo đẩy trên thiết bị này.']);
    }

    // DELETE /api/minihouse/portal/push-token — gọi lúc đăng xuất/tắt nhận thông báo, để tránh gửi
    // push tới thiết bị không còn đăng nhập khách này nữa. Chỉ xoá token thuộc ĐÚNG khách đang gọi —
    // không cho xoá token của khách khác dù trùng chuỗi token (hiếm nhưng không tin dữ liệu client gửi).
    public function unregisterPushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:1000'],
        ]);

        TenantPushToken::where('token', $data['token'])->where('tenant_id', $this->tenant($request)->id)->delete();

        return response()->json(['message' => 'Đã huỷ đăng ký nhận thông báo đẩy trên thiết bị này.']);
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
            // Nợ cộng dồn từ hoá đơn tháng trước cùng hợp đồng — CÙNG công thức đang dùng ở phiếu in/
            // mã QR/tin Zalo, SMS nhắc nợ (InvoiceContentRenderer::previousDebt()).
            'previous_debt'   => InvoiceContentRenderer::previousDebt($invoice),
            'total_owed'      => InvoiceContentRenderer::totalOwed($invoice),
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
