<?php

namespace Modules\Minihouse\Http\Controllers\Portal\Concerns;

use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\TenantFeedback;
use Modules\Minihouse\App\Services\InvoiceMomoService;
use Modules\Minihouse\App\Services\InvoicePayOsService;
use Modules\Minihouse\App\Services\InvoiceVnpayService;
use Modules\Minihouse\App\Services\TenantPortalService;

// Dùng CHUNG bởi Portal web (session, Modules\Minihouse\Http\Controllers\Portal\TenantPortalController)
// VÀ API Portal (token Sanctum, app\Http\Controllers\Api\Minihouse\Portal\TenantPortalApiController)
// — 2 giao diện phải trả lời "khách xem/thanh toán được đúng những gì" GIỐNG HỆT NHAU. abort_unless()
// bên dưới hoạt động đúng cho CẢ 2: Laravel tự trả JSON cho request "Accept: application/json" (API)
// và trang lỗi HTML cho request thường (web), không cần viết riêng.
trait InteractsWithTenantPortalData
{
    protected function paymentsQuery(Tenant $tenant)
    {
        $contractIds = TenantPortalService::tenantContracts($tenant)->pluck('id');
        $invoiceIds = Invoice::whereIn('contract_id', $contractIds)
            ->whereNotNull('electric_end')->whereNotNull('water_end')
            ->pluck('id');

        return InvoicePayment::whereIn('invoice_id', $invoiceIds)
            ->with('invoice')
            ->orderByDesc('paid_at');
    }

    // whereNotNull(electric_end, water_end) — xem Invoice::isReadyForTenant(): ẩn hoá đơn vừa lập
    // hàng loạt còn thiếu chỉ số điện/nước cho tới khi nhân viên bổ sung xong (InvoiceObserver tự
    // báo Portal đúng lúc đó), tránh khách thấy 1 hoá đơn thiếu tiền rồi tổng tự đổi sau.
    protected function invoicesQuery(Tenant $tenant)
    {
        $contractIds = TenantPortalService::tenantContracts($tenant)->pluck('id');

        return Invoice::whereIn('contract_id', $contractIds)
            ->whereNotNull('electric_end')->whereNotNull('water_end')
            ->with(['contract' => fn ($q) => $q->withoutGlobalScopes()->with(['room' => fn ($q2) => $q2->withoutGlobalScopes()])])
            ->orderByDesc('month');
    }

    // KHÔNG withoutGlobalScopes() trên Invoice — Invoice dùng SoftDeletes và hoá đơn đã xoá mềm PHẢI
    // biến mất khỏi Portal. Vẫn giữ withoutGlobalScopes() cho Contract/Room/Building (đúng ý định
    // cũ: hiện được dữ liệu lịch sử dù hợp đồng/phòng liên quan đã xoá mềm).
    protected function findOwnedInvoice(Tenant $tenant, int $id): Invoice
    {
        $invoice = Invoice::with([
                'contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
                'items',
            ])
            ->findOrFail($id);

        abort_unless(TenantPortalService::invoiceBelongsToTenant($invoice, $tenant), 403);
        // Chưa đủ chỉ số điện/nước — coi như "chưa tồn tại" với khách, kể cả khi khách bấm lại đúng
        // link cũ (VD từ thông báo khác) trước khi nhân viên bổ sung xong.
        abort_unless($invoice->isReadyForTenant(), 404);

        return $invoice;
    }

    protected function findOwnedInvoiceForPayment(Tenant $tenant, int $id): Invoice
    {
        $invoice = Invoice::with([
                'contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->findOrFail($id);

        abort_unless(TenantPortalService::invoiceBelongsToTenant($invoice, $tenant), 403);
        abort_unless($invoice->isReadyForTenant(), 404);

        return $invoice;
    }

    protected function createFeedback(Tenant $tenant, array $data): TenantFeedback
    {
        $activeContract = TenantPortalService::tenantContracts($tenant)->firstWhere('status', Contract::STATUS_ACTIVE);

        return TenantFeedback::create([
            'room_id'      => $activeContract?->room_id ?? $tenant->room_id,
            'tenant_id'    => $tenant->id,
            'tenant_name'  => $tenant->fullname,
            'tenant_phone' => $tenant->phone,
            'rating'       => $data['rating'],
            'content'      => $data['content'] ?? null,
        ]);
    }

    // QUAN TRỌNG: $returnUrl phải là route riêng của giao diện đang gọi (Portal web HOẶC API) —
    // mặc định của cả 3 service này là trang Sửa hoá đơn bên panel NHÂN VIÊN (guard "web"), khách
    // thuê (guard "tenant") thanh toán xong sẽ bị cổng thanh toán đưa nhầm về đó và va vào tường
    // đăng nhập admin không có quyền vào.
    protected function createPayment(Invoice $invoice, string $returnUrl, ?string $clientIp): ?array
    {
        $building = $invoice->contract?->room?->building;
        $method = $building?->activePaymentMethod();

        return match ($method) {
            Building::PAYMENT_METHOD_PAYOS  => TenantPortalService::normalizePayOsResult(InvoicePayOsService::createQr($invoice, $returnUrl)),
            Building::PAYMENT_METHOD_MOMO   => TenantPortalService::normalizeMomoResult(InvoiceMomoService::createPaymentRequest($invoice, $returnUrl)),
            Building::PAYMENT_METHOD_VNPAY  => TenantPortalService::normalizeVnpayResult(InvoiceVnpayService::createPaymentUrl($invoice, (string) $clientIp, $returnUrl)),
            Building::PAYMENT_METHOD_VIETQR => $building ? TenantPortalService::normalizeVietQrResult($invoice, $building) : null,
            default => null,
        };
    }
}
