<?php

namespace Modules\Minihouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfSigning\ContractPdfRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Services\InvoiceContentRenderer;
use Modules\Minihouse\App\Services\InvoicePayOsService;
use Modules\Minihouse\App\Services\VietQrService;
use Symfony\Component\HttpFoundation\Response;

// Xuất PDF/In phiếu "Thông báo tiền phòng trọ" — tự sinh lại từ dữ liệu hoá đơn MỚI NHẤT mỗi lần in
// (khác Contract in theo đúng nội dung ĐÃ LƯU — hoá đơn không có bước "chốt nội dung" riêng như hợp
// đồng, nên luôn phản ánh đúng số liệu hiện tại). Route thường (không qua Livewire), lý do giống hệt
// ContractPrintController: cần mở PDF inline ở tab mới, Livewire không hỗ trợ tốt việc này.
class InvoicePrintController extends Controller
{
    public function show(Request $request, Invoice $invoice): Response
    {
        $user = $request->user();

        abort_unless($user?->isSuperAdmin() || $user?->can('view_any_invoices'), 403);

        $contract = $this->resolveContract($invoice);

        $this->authorizeBuilding($user, $contract);

        $qrImageSrc = $this->resolveQrImageSrc($invoice, $contract);

        $pdf = ContractPdfRenderer::render(InvoiceContentRenderer::renderPrintable($invoice, $qrImageSrc));

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"phieu-thu-hoa-don-{$invoice->id}.pdf\"",
        ]);
    }

    // In hàng loạt (nút "In hoá đơn đã chọn" trên InvoiceTable) — ghép nhiều phiếu vào 1 file PDF duy
    // nhất (ngắt trang giữa các phiếu bằng page-break-after), thay vì mở nhiều tab/nhiều lần tải rời
    // rạc mà trình duyệt thường chặn. Hoá đơn nào người dùng không có quyền xem (khác toà được phân
    // công) thì ÂM THẦM BỎ QUA khỏi bản in gộp — không abort cả loạt chỉ vì lẫn 1 hoá đơn ngoài quyền.
    public function bulk(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user?->isSuperAdmin() || $user?->can('view_any_invoices'), 403);

        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 404);

        $invoices = Invoice::withoutGlobalScopes()->whereIn('id', $ids)->orderByDesc('month')->get();

        $pages = [];

        foreach ($invoices as $invoice) {
            $contract = $this->resolveContract($invoice);

            if (! $user->isSuperAdmin()) {
                $buildingId = $contract?->room?->building_id;

                if (! $buildingId || ! in_array($buildingId, $user->rootBuildingIds())) {
                    continue;
                }
            }

            $qrImageSrc = $this->resolveQrImageSrc($invoice, $contract);
            $pages[]    = InvoiceContentRenderer::renderPrintable($invoice, $qrImageSrc);
        }

        abort_if(empty($pages), 403);

        $html = implode('', array_map(
            fn (string $page, int $index) => $index < count($pages) - 1
                ? '<div style="page-break-after: always;">' . $page . '</div>'
                : $page,
            $pages,
            array_keys($pages),
        ));

        $pdf = ContractPdfRenderer::render($html);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"phieu-thu-hoa-don-hang-loat.pdf\"",
        ]);
    }

    // Tự truy vấn withoutGlobalScopes() thay vì quan hệ $invoice->contract mặc định — quan hệ mặc định
    // áp cả SoftDeletes lẫn ActiveBuildingScope, nên hợp đồng ĐÃ XOÁ MỀM (hoá đơn cũ là dữ liệu lịch
    // sử, vẫn cần in được bình thường) sẽ trả về null, kéo theo mất building_id (chặn nhầm quyền xem)
    // VÀ mất luôn bước tạo QR (không xác định được activePaymentMethod()).
    private function resolveContract(Invoice $invoice): ?Contract
    {
        return $invoice->contract_id
            ? Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($invoice->contract_id)
            : null;
    }

    // Invoice::ScopedToActiveBuildingViaContract chỉ lọc trong panel Filament (xem
    // ActiveBuildingScope::isPanelActive()) — route thường này KHÔNG có context panel nên global scope
    // không tự áp dụng cho route-model-binding {invoice} — phải tự kiểm tra lại đúng ranh giới toà
    // nhà, không cho đổi id trên URL để xem hoá đơn của toà nhà khác được phân công.
    private function authorizeBuilding($user, ?Contract $contract): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $buildingId = $contract?->room?->building_id;

        abort_unless($buildingId && in_array($buildingId, $user->rootBuildingIds()), 403);
    }

    // Dùng đúng kiểu thanh toán đã CHỌN cho toà nhà (Building::activePaymentMethod(), xem BuildingForm
    // mục "Kiểu thanh toán") — không tự suy luận theo trường nào có dữ liệu. PayOS lỗi (best-effort)
    // thì để trống QR luôn, KHÔNG tự rơi về VietQR — toà đã chọn "PayOS riêng" thường không điền
    // owner_bank_*, hiện nhầm QR ngân hàng chưa chắc đúng còn nguy hiểm hơn.
    private function resolveQrImageSrc(Invoice $invoice, ?Contract $contract): ?string
    {
        $building     = $contract?->room?->building;
        $activeMethod = $building?->activePaymentMethod();
        $qrImageSrc   = null;

        if ($activeMethod === Building::PAYMENT_METHOD_PAYOS && $invoice->remainingAmount() > 0) {
            try {
                // Còn 1 mã CHƯA hết hạn thì dùng lại đúng mã đó — tránh huỷ link cũ khách đang thao
                // tác dở mỗi lần bấm in lại phiếu.
                $rawQrCode = $invoice->hasActivePayOsQr()
                    ? $invoice->payos_qr_code
                    : InvoicePayOsService::createQr($invoice)['qr_code'];

                if (filled($rawQrCode)) {
                    // QrCodeGenerator vẽ THẲNG từ chuỗi QR (local, không cần gọi mạng) — khác nhánh
                    // VietQR bên dưới phải tự tải ảnh về base64 vì Dompdf tắt isRemoteEnabled.
                    $qrImageSrc = QrCodeGenerator::dataUri($rawQrCode, 260);
                }
            } catch (\Throwable $e) {
                Log::warning('InvoicePrintController: tạo QR PayOS riêng thất bại, in phiếu không kèm QR', [
                    'invoice_id'  => $invoice->id,
                    'building_id' => $building->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Dompdf (dùng trong ContractPdfRenderer) tắt isRemoteEnabled — phải tự tải ảnh QR VietQR về
        // rồi nhúng base64 vào HTML trước khi render, nếu không ảnh QR sẽ KHÔNG hiện trong PDF. CHỈ
        // chạy khi toà nhà đã CHỌN đúng kiểu "VietQR" — không tự rơi về đây khi PayOS lỗi, vì toà đã
        // chọn "PayOS riêng" thường không điền owner_bank_* nên hiện QR sai/không đúng ý.
        if (! $qrImageSrc && $activeMethod === Building::PAYMENT_METHOD_VIETQR) {
            $amount = InvoiceContentRenderer::totalOwed($invoice);
            $url    = VietQrService::imageUrl($building, $amount, 'Tien phong ' . $contract?->room?->code . ' thang ' . $invoice->month?->format('m/Y'));
            $qrImageSrc = $url ? VietQrService::toDataUri($url) : null;
        }

        return $qrImageSrc;
    }
}
