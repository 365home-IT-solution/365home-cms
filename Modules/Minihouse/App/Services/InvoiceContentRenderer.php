<?php

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;

// Sinh nội dung "Thông báo tiền phòng trọ" (phiếu thu) từ ĐÚNG dữ liệu hoá đơn đang có — theo mẫu
// giấy quen thuộc (Khoản/Chi tiết/Thành Tiền, Phần Thanh toán, Chi tiết thanh toán) mà chủ nhà đã
// dùng trước đây, có thêm mã QR chuyển khoản đúng tài khoản ngân hàng của CHỦ TOÀ NHÀ tương ứng
// (khác PayOS dùng chung 1 tài khoản cho toàn hệ thống — mỗi toà nhà ở đây có thể có chủ sở hữu và
// tài khoản thu tiền RIÊNG, xem Building::hasOwnerBankInfo()).
class InvoiceContentRenderer
{
    // renderPrintable() dùng cho CẢ 2 nơi: xem trực tiếp trên trình duyệt (route thường, ảnh QR tải
    // thẳng qua URL VietQR) và xuất PDF qua Dompdf (ảnh QR PHẢI đã là data URI base64 — Dompdf tắt
    // isRemoteEnabled nên không tự tải được ảnh từ URL ngoài, xem VietQrService::toDataUri()). Truyền
    // sẵn $qrImageSrc từ ngoài vào thay vì tự quyết bên trong, để không phải gọi HTTP lồng trong
    // renderer thuần hiển thị.
    public static function renderPrintable(Invoice $invoice, ?string $qrImageSrc = null): string
    {
        $invoice->loadMissing(['items']);

        // Hoá đơn là DỮ LIỆU LỊCH SỬ — phải in được nguyên vẹn dù hợp đồng gắn với nó SAU NÀY bị xoá
        // (mềm/khỏi phạm vi toà đang lọc). Cố tình KHÔNG dùng quan hệ $invoice->contract (áp global
        // scope SoftDeletes + ActiveBuildingScope mặc định, sẽ trả về null làm rỗng cả phiếu — đúng
        // lỗi thực tế gặp phải khi in hoá đơn của 1 hợp đồng đã xoá mềm) — tự truy vấn thẳng
        // withoutGlobalScopes() để luôn lấy được đúng bản ghi bất kể trạng thái hiện tại.
        $contract = $invoice->contract_id
            ? Contract::withoutGlobalScopes()
                ->with([
                    // Room/Building/Tenant CŨNG dùng SoftDeletes riêng — bỏ qua scope ở TỪNG mắt xích,
                    // không chỉ ở Contract, để 1 phòng/toà/khách bị xoá sau này không làm rỗng lây
                    // sang các mắt xích còn lại của phiếu.
                    'room'          => fn ($q) => $q->withoutGlobalScopes(),
                    'room.building' => fn ($q) => $q->withoutGlobalScopes(),
                    'tenant'        => fn ($q) => $q->withoutGlobalScopes(),
                ])
                ->find($invoice->contract_id)
            : null;
        $room     = $contract?->room;
        $building = $room?->building;
        $tenant   = $contract?->tenant;

        $monthLabel = $invoice->month?->format('m/Y') ?? '...................';
        $monthText  = $invoice->month ? ('Tháng ' . $invoice->month->format('n') . ' năm ' . $invoice->month->format('Y')) : '...................';

        $tenantName = e($tenant?->fullname ?? '...................');
        $tenantCccd = e($tenant?->id_card_number ?? '...................');
        $roomCode   = e($room?->code ?? '...................');

        $rows = [];
        $stt  = 1;

        $rows[] = [$stt++, 'Phòng', '', $invoice->room_price];

        if (filled($invoice->electric_amount) && (float) $invoice->electric_amount > 0) {
            $qty = (float) $invoice->electric_end - (float) $invoice->electric_start;
            $rows[] = [
                $stt++,
                'Điện',
                sprintf(
                    '( %s - %s ) = %sKw x %s đ/Kw',
                    self::number($invoice->electric_end),
                    self::number($invoice->electric_start),
                    self::number($qty),
                    self::number($invoice->electric_unit_price),
                ),
                $invoice->electric_amount,
            ];
        }

        if (filled($invoice->water_amount) && (float) $invoice->water_amount > 0) {
            $qty = (float) $invoice->water_end - (float) $invoice->water_start;
            $rows[] = [
                $stt++,
                'Nước',
                sprintf(
                    '( %s - %s ) = %sm3 x %s đ/m3',
                    self::number($invoice->water_end),
                    self::number($invoice->water_start),
                    self::number($qty),
                    self::number($invoice->water_unit_price),
                ),
                $invoice->water_amount,
            ];
        }

        foreach ($invoice->items as $item) {
            $rows[] = [$stt++, e($item->name), '', $item->amount];
        }

        $rowsHtml = collect($rows)->map(fn (array $r) => sprintf(
            '<tr>
                <td style="border:1px solid #111827;padding:4px 6px;text-align:center;">%s</td>
                <td style="border:1px solid #111827;padding:4px 6px;">%s</td>
                <td style="border:1px solid #111827;padding:4px 6px;">%s</td>
                <td style="border:1px solid #111827;padding:4px 6px;text-align:right;">%s</td>
            </tr>',
            $r[0],
            $r[1],
            $r[2],
            self::money($r[3]),
        ))->implode('');

        $previousDebt = self::previousDebt($invoice);
        $thisMonth    = (float) $invoice->total_amount;
        $grandTotal   = self::totalOwed($invoice);

        $thisMonthMoney  = self::money($thisMonth);
        $grandTotalMoney = self::money($grandTotal);
        $previousDebtMoney = self::moneyOrDash($previousDebt);

        $qrSection = self::renderQrSection($building, $grandTotal, $roomCode, $monthLabel, $qrImageSrc);

        return <<<HTML
            <div style="font-family:'DejaVu Sans',sans-serif;font-size:12px;color:#111827;border:1px solid #111827;padding:16px;">
                <div style="text-align:center;">
                    <div style="font-weight:700;font-size:16px;">THÔNG BÁO TIỀN PHÒNG TRỌ</div>
                    <div>{$monthText}</div>
                </div>

                <table style="width:100%;margin-top:14px;border-collapse:collapse;">
                    <tr>
                        <td style="width:60%;">Kính gửi&nbsp;: <strong>{$tenantName}</strong></td>
                        <td style="width:40%;">CMND số: {$tenantCccd}</td>
                    </tr>
                    <tr>
                        <td>Ở phòng số:&nbsp; <strong>{$roomCode}</strong></td>
                        <td></td>
                    </tr>
                </table>

                <div style="margin-top:10px;">
                    Xin thông báo ông bà biết, tiền thuê phòng và các chi phí dịch vụ khác trong tháng {$monthText} .Cụ thể như sau:
                </div>

                <table style="width:100%;border-collapse:collapse;margin-top:10px;">
                    <tr style="background:#f3f4f6;font-weight:700;">
                        <td style="border:1px solid #111827;padding:4px 6px;text-align:center;width:6%;">STT</td>
                        <td style="border:1px solid #111827;padding:4px 6px;width:16%;">Khoản</td>
                        <td style="border:1px solid #111827;padding:4px 6px;">Chi tiết</td>
                        <td style="border:1px solid #111827;padding:4px 6px;width:18%;text-align:right;">Thành Tiền</td>
                    </tr>
                    {$rowsHtml}
                    <tr>
                        <td colspan="3" style="border:1px solid #111827;padding:4px 6px;text-align:right;font-weight:700;">Cộng:</td>
                        <td style="border:1px solid #111827;padding:4px 6px;text-align:right;font-weight:700;">{$thisMonthMoney}</td>
                    </tr>
                </table>

                <div style="margin-top:14px;">
                    <div style="font-weight:700;text-decoration:underline;">Phần Thanh toán:</div>
                    <table style="width:100%;margin-top:4px;">
                        <tr>
                            <td style="width:70%;">-Số tiền còn nợ tháng trước :</td>
                            <td style="text-align:right;">{$previousDebtMoney}</td>
                        </tr>
                        <tr>
                            <td>-Phải trả tháng này:</td>
                            <td style="text-align:right;">{$thisMonthMoney}</td>
                        </tr>
                        <tr>
                            <td style="font-weight:700;padding-left:16px;">Tổng Cộng:</td>
                            <td style="text-align:right;font-weight:700;">{$grandTotalMoney}</td>
                        </tr>
                    </table>
                </div>

                <div style="margin-top:14px;font-weight:700;">Chi tiết thanh toán:</div>
                {$qrSection}
            </div>
        HTML;
    }

    // Tổng số tiền THỰC SỰ cần chuyển khoản (nợ tháng trước + phải trả tháng này) — dùng CHUNG cho cả
    // số hiện trên "Tổng Cộng" của phiếu VÀ số tiền đúc vào mã QR, để 2 nơi luôn khớp nhau (đặc biệt
    // quan trọng vì InvoicePrintController phải tự tính trước số tiền này để tải ảnh QR về nhúng vào
    // PDF, tách biệt khỏi phần render HTML chính).
    public static function totalOwed(Invoice $invoice): float
    {
        return self::previousDebt($invoice) + (float) $invoice->total_amount;
    }

    // Tổng số tiền CÒN THIẾU (total_amount - amount_paid) của các hoá đơn THÁNG TRƯỚC cùng hợp đồng
    // này, vẫn ở trạng thái chưa/1 phần thanh toán — hiển thị cho khách biết còn nợ từ trước, KHÔNG
    // tính vào chính hoá đơn đang xem (Invoice::amount_paid chỉ theo dõi số đã trả cho HOÁ ĐƠN NÀY).
    // Public — dùng chung với MinihouseZaloService::buildTemplateData() (tin nhắc đóng tiền qua
    // Zalo cần hiện đúng số nợ tháng trước giống hệt phiếu in).
    public static function previousDebt(Invoice $invoice): float
    {
        if (! $invoice->contract_id || ! $invoice->month) {
            return 0;
        }

        return (float) Invoice::where('contract_id', $invoice->contract_id)
            ->where('id', '!=', $invoice->id)
            ->where('month', '<', $invoice->month)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->get()
            ->sum(fn (Invoice $inv) => $inv->remainingAmount());
    }

    // $qrImageSrc: truyền sẵn data URI base64 khi xuất PDF (Dompdf không tự tải được ảnh từ URL
    // ngoài — xem VietQrService::toDataUri()); để trống (null) thì tự dùng thẳng URL VietQR — dùng
    // cho trang xem trực tiếp trên trình duyệt, nơi ảnh tự tải bình thường.
    private static function renderQrSection(?Building $building, float $amount, string $roomCode, string $monthLabel, ?string $qrImageSrc): string
    {
        $method = $building?->activePaymentMethod();

        if (! $building || ! $method) {
            return '<div style="margin-top:8px;color:#6b7280;">.......................................................................</div>';
        }

        // $qrImageSrc do InvoicePrintController truyền sẵn (PayOS lẫn VietQR đều cần base64 cho
        // PDF) — chỉ tự dựng URL VietQR ở đây khi gọi trực tiếp không qua controller đó (VD test)
        // VÀ toà đã CHỌN đúng kiểu VietQR.
        $src = $qrImageSrc ?? ($method === Building::PAYMENT_METHOD_VIETQR
            ? VietQrService::imageUrl($building, $amount, 'Tien phong ' . $roomCode . ' thang ' . $monthLabel)
            : null);

        if (! $src) {
            return '<div style="margin-top:8px;color:#6b7280;">.......................................................................</div>';
        }

        // VietQR hiện thêm thông tin ngân hàng bên cạnh QR (khách đối chiếu tay được nếu cần); PayOS
        // không cần — QR/trang thanh toán PayOS tự đủ thông tin, owner_bank_* cũng có thể chưa được
        // điền cho toà chọn kiểu PayOS riêng.
        $bankInfoHtml = $method === Building::PAYMENT_METHOD_VIETQR
            ? <<<HTML
                <div>Chủ tài khoản: <strong>{$building->owner_bank_account_holder}</strong></div>
                <div>Ngân hàng: {$building->owner_bank_name}</div>
                <div>Số tài khoản: <strong>{$building->owner_bank_account_number}</strong></div>
                HTML
            : '<div>Thanh toán qua PayOS</div>';

        return <<<HTML
            <table style="width:100%;margin-top:8px;">
                <tr>
                    <td style="width:150px;vertical-align:top;">
                        <img src="{$src}" alt="QR chuyển khoản" style="width:140px;height:140px;" />
                    </td>
                    <td style="vertical-align:top;padding-left:12px;">
                        {$bankInfoHtml}
                        <div style="margin-top:4px;color:#6b7280;">Quét mã QR bằng app ngân hàng để chuyển khoản đúng số tiền trên.</div>
                    </td>
                </tr>
            </table>
        HTML;
    }

    // Nhận nullable — chỉ số điện/nước (electric_start/end, unit_price) có thể để trống trên hoá đơn
    // cũ/nhập thiếu dữ liệu (VD chỉ ghi electric_amount tay mà không qua recalcElectric() của
    // InvoiceForm) — coi null như 0 để phiếu vẫn in ra được thay vì lỗi 500 (TypeError).
    private static function number(?float $value): string
    {
        return rtrim(rtrim(number_format($value ?? 0, 2, ',', '.'), '0'), ',');
    }

    private static function money(?float $value): string
    {
        return number_format($value ?? 0, 0, ',', '.');
    }

    private static function moneyOrDash(float $value): string
    {
        return $value > 0 ? self::money($value) : '-';
    }
}
