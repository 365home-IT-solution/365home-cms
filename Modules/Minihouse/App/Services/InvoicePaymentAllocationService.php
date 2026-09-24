<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;

// Phân bổ 1 khoản tiền nhận được qua cổng thanh toán online (PayOS/MoMo/VNPay — số tiền ĐÃ gồm cả nợ
// tháng trước, xem InvoiceContentRenderer::totalOwed()) thành NHIỀU InvoicePayment riêng biệt, mỗi
// hoá đơn ĐÚNG 1 khoản BẰNG TRÒN total_amount của hoá đơn đó — bắt buộc phải làm vậy vì
// Invoice::validateSinglePayment() áp dụng NHẤT QUÁN cho MỌI khoản thanh toán (kể cả tự động qua
// webhook): 1 hoá đơn chỉ nhận đúng 1 lần duy nhất, đúng bằng tổng tiền, không hỗ trợ trả từng phần.
// Trả nợ CŨ NHẤT trước (FIFO theo tháng), phần còn lại dồn cho hoá đơn ĐANG THANH TOÁN sau cùng.
//
// Hoá đơn cũ đang ở trạng thái "partial" (đã có sẵn 1 khoản thanh toán dở dang — chỉ xảy ra với dữ
// liệu cũ trước khi validateSinglePayment() ra đời, hoặc do nhân viên chỉnh tay ngoài luồng chuẩn) bị
// BỎ QUA hoàn toàn khỏi phân bổ — không có cách nào "trả thêm" cho nó theo đúng luật hiện hành, nhân
// viên phải xử lý tay riêng khoản đó.
class InvoicePaymentAllocationService
{
    /**
     * @return array{payments: array<int, InvoicePayment>, unallocated: float} unallocated > 0 (hoặc
     *   < 0) nghĩa là số tiền nhận được không khớp đúng tổng các hoá đơn đã phân bổ được — KHÔNG nên
     *   xảy ra nếu $amountReceived đúng bằng totalOwed() tại đúng thời điểm khách quét mã, nhưng có
     *   thể lệch nếu 1 hoá đơn cũ vừa được thanh toán qua kênh khác GIỮA lúc tạo mã và lúc webhook xác
     *   nhận — hàm này CHỈ log cảnh báo, không tự "nhét" phần lệch vào bất kỳ hoá đơn nào để tránh ghi
     *   sai dữ liệu, người gọi tự quyết định có cần báo nhân viên kiểm tra tay hay không.
     */
    public static function allocate(Invoice $currentInvoice, float $amountReceived, string $paymentMethod, string $noteBase): array
    {
        return DB::transaction(function () use ($currentInvoice, $amountReceived, $paymentMethod, $noteBase) {
            $chain = InvoiceContentRenderer::contractIdChain($currentInvoice);

            // lockForUpdate() cho CẢ nhóm hoá đơn nợ cũ lẫn hoá đơn hiện tại — tránh 2 lần gọi webhook
            // gần như đồng thời (PayOS/MoMo/VNPay tự retry khi chưa nhận phản hồi kịp) cùng đọc thấy
            // "chưa có thanh toán" rồi cùng tạo trùng, cùng nguyên tắc DB::transaction() đã dùng ở
            // PayOsWebhookController/MomoWebhookController/VnpayIpnController trước khi có hàm này.
            $debtInvoices = Invoice::withoutGlobalScopes()
                ->whereIn('contract_id', $chain)
                ->where('id', '!=', $currentInvoice->id)
                ->where('month', '<', $currentInvoice->month)
                ->where('status', Invoice::STATUS_UNPAID)
                ->orderBy('month')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Invoice $inv) => $inv->payments()->doesntExist());

            Invoice::whereKey($currentInvoice->id)->lockForUpdate()->first();

            $remaining = $amountReceived;
            $created   = [];

            foreach ($debtInvoices as $debtInvoice) {
                $due = (float) $debtInvoice->total_amount;

                // Không đủ tiền trả HẾT hoá đơn cũ tiếp theo — dừng lại, không trả 1 phần (phạm luật
                // validateSinglePayment()). Trường hợp này chỉ xảy ra khi $amountReceived tính thiếu
                // so với totalOwed() thật tại thời điểm này (VD nợ cũ vừa phát sinh thêm SAU khi tạo
                // mã QR) — log cảnh báo ở cuối hàm, không throw để không chặn phần đã phân bổ được.
                if ($remaining + 1 < $due) {
                    break;
                }

                $created[] = $debtInvoice->payments()->create([
                    'amount'         => $due,
                    'paid_at'        => now(),
                    'payment_method' => $paymentMethod,
                    'note'           => $noteBase . ' (trả nợ hoá đơn tháng ' . $debtInvoice->month->format('m/Y') . ')',
                    'status'         => InvoicePayment::STATUS_APPROVED,
                    'approved_at'    => now(),
                    'created_by'     => null,
                ]);

                $remaining -= $due;
            }

            // "$remaining + 1 >= total_amount", KHÔNG PHẢI so khớp tuyệt đối — tiền dư ra sau khi trả
            // hết nợ cũ CÓ THỂ nhiều hơn đúng total_amount hoá đơn hiện tại (VD 1 hoá đơn cũ "partial"
            // bị bỏ qua ở vòng lặp trên khiến phần lẽ ra dành cho nó dồn dư sang đây) — vẫn phải trả
            // ĐỦ hoá đơn hiện tại trước, phần dư thật sự mới tính là "unallocated" cần nhân viên kiểm
            // tra, KHÔNG được để sót hẳn việc thanh toán hoá đơn hiện tại chỉ vì có dư thêm 1 khoản.
            if ($currentInvoice->payments()->doesntExist() && $remaining + 1 >= (float) $currentInvoice->total_amount) {
                $created[] = $currentInvoice->payments()->create([
                    'amount'         => (float) $currentInvoice->total_amount,
                    'paid_at'        => now(),
                    'payment_method' => $paymentMethod,
                    'note'           => $noteBase,
                    'status'         => InvoicePayment::STATUS_APPROVED,
                    'approved_at'    => now(),
                    'created_by'     => null,
                ]);

                $remaining -= (float) $currentInvoice->total_amount;
            }

            if (abs($remaining) > 1) {
                Log::warning('InvoicePaymentAllocationService: còn dư/thiếu tiền sau khi phân bổ — cần nhân viên kiểm tra tay', [
                    'invoice_id'       => $currentInvoice->id,
                    'amount_received'  => $amountReceived,
                    'unallocated'      => $remaining,
                    'invoices_paid'    => collect($created)->pluck('invoice_id')->all(),
                ]);
            }

            return ['payments' => $created, 'unallocated' => $remaining];
        });
    }
}
