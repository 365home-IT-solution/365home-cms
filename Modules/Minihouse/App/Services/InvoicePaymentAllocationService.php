<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Services;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;

// Phân bổ 1 khoản tiền nhận được (qua cổng thanh toán online PayOS/MoMo/VNPay, HOẶC nhân viên ghi tay
// gộp cả nợ cũ — số tiền ĐÃ gồm nợ tháng trước, xem InvoiceContentRenderer::totalOwed()) thành NHIỀU
// InvoicePayment riêng biệt, mỗi hoá đơn ĐÚNG 1 khoản BẰNG TRÒN total_amount của hoá đơn đó — bắt
// buộc phải làm vậy vì Invoice::validateSinglePayment() áp dụng NHẤT QUÁN cho MỌI khoản thanh toán:
// 1 hoá đơn chỉ nhận đúng 1 lần duy nhất, đúng bằng tổng tiền, không hỗ trợ trả từng phần. Trả nợ CŨ
// NHẤT trước (FIFO theo tháng), phần còn lại dồn cho hoá đơn ĐANG THANH TOÁN sau cùng.
//
// Hoá đơn cũ đang ở trạng thái "partial" (đã có sẵn 1 khoản thanh toán dở dang — chỉ xảy ra với dữ
// liệu cũ trước khi validateSinglePayment() ra đời, hoặc do nhân viên chỉnh tay ngoài luồng chuẩn) bị
// BỎ QUA hoàn toàn khỏi phân bổ — không có cách nào "trả thêm" cho nó theo đúng luật hiện hành; nhân
// viên được BÁO QUA AdminNotificationService::notify() (xem notifyUnallocated()) để xử lý tay riêng
// khoản đó, không chỉ nằm im trong log server.
class InvoicePaymentAllocationService
{
    /**
     * @param  string  $status  InvoicePayment::STATUS_APPROVED (cổng online đã tự xác nhận tiền thật,
     *                          duyệt ngay — mặc định) hoặc STATUS_PENDING (nhân viên tự ghi tay, vẫn
     *                          cần "Chủ toà nhà" duyệt riêng TỪNG khoản qua đúng quy trình cũ, xem
     *                          InvoicePaymentController::approve()).
     * @param  ?string  $createdBy  ID nhân viên ghi nhận — null khi tới từ cổng thanh toán online
     *                              (không ai "tạo" cả, hệ thống tự ghi).
     * @return array{payments: array<int, InvoicePayment>, unallocated: float} unallocated > 0 (hoặc
     *   < 0) nghĩa là số tiền nhận được không khớp đúng tổng các hoá đơn đã phân bổ được — KHÔNG nên
     *   xảy ra nếu $amountReceived đúng bằng totalOwed() tại đúng thời điểm tính, nhưng có thể lệch
     *   nếu 1 hoá đơn cũ vừa được thanh toán qua kênh khác GIỮA lúc tính và lúc ghi nhận thật — hàm
     *   này CHỈ log + báo nhân viên, không tự "nhét" phần lệch vào bất kỳ hoá đơn nào để tránh ghi sai
     *   dữ liệu.
     */
    public static function allocate(
        Invoice $currentInvoice,
        float $amountReceived,
        string $paymentMethod,
        string $noteBase,
        string $status = InvoicePayment::STATUS_APPROVED,
        ?string $createdBy = null,
        ?Carbon $paidAt = null,
    ): array {
        $paidAt = $paidAt ?? now();

        return DB::transaction(function () use ($currentInvoice, $amountReceived, $paymentMethod, $noteBase, $status, $createdBy, $paidAt) {
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

            // Hoá đơn cũ đang "1 phần" — KHÔNG tham gia phân bổ (xem docblock lớp), chỉ lấy ra để BÁO
            // CHO NHÂN VIÊN biết cụ thể hoá đơn nào đang giữ phần tiền dư, tránh dư ra rồi im lặng mất
            // dấu trong log server.
            $skippedPartials = Invoice::withoutGlobalScopes()
                ->whereIn('contract_id', $chain)
                ->where('id', '!=', $currentInvoice->id)
                ->where('month', '<', $currentInvoice->month)
                ->where('status', Invoice::STATUS_PARTIAL)
                ->orderBy('month')
                ->get();

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
                    'paid_at'        => $paidAt,
                    'payment_method' => $paymentMethod,
                    'note'           => $noteBase . ' (trả nợ hoá đơn tháng ' . $debtInvoice->month->format('m/Y') . ')',
                    'status'         => $status,
                    'approved_at'    => $status === InvoicePayment::STATUS_APPROVED ? now() : null,
                    'created_by'     => $createdBy,
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
                    'paid_at'        => $paidAt,
                    'payment_method' => $paymentMethod,
                    'note'           => $noteBase,
                    'status'         => $status,
                    'approved_at'    => $status === InvoicePayment::STATUS_APPROVED ? now() : null,
                    'created_by'     => $createdBy,
                ]);

                $remaining -= (float) $currentInvoice->total_amount;
            }

            if (abs($remaining) > 1) {
                Log::warning('InvoicePaymentAllocationService: còn dư/thiếu tiền sau khi phân bổ — cần nhân viên kiểm tra tay', [
                    'invoice_id'       => $currentInvoice->id,
                    'amount_received'  => $amountReceived,
                    'unallocated'      => $remaining,
                    'invoices_paid'    => collect($created)->pluck('invoice_id')->all(),
                    'skipped_partials' => $skippedPartials->pluck('id')->all(),
                ]);

                self::notifyUnallocated($currentInvoice, $remaining, $skippedPartials);
            }

            return ['payments' => $created, 'unallocated' => $remaining];
        });
    }

    // Báo cho nhân viên quản lý đúng toà nhà (+ super_admin) biết còn tiền chưa gán được hoá đơn nào
    // — KHÔNG để chỉ nằm trong log server không ai đọc. Lỗi gửi thông báo KHÔNG được làm hỏng giao
    // dịch đã ghi nhận thành công ở trên (try/catch riêng, ngoài transaction chính đã commit).
    private static function notifyUnallocated(Invoice $currentInvoice, float $unallocated, Collection $skippedPartials): void
    {
        try {
            $buildingId = Contract::withoutGlobalScopes()
                ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
                ->find($currentInvoice->contract_id)
                ?->room?->building_id;

            $recipients = User::query()
                ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', config('filament-shield.super_admin.name')))
                    ->orWhere('partner_id', '!=', null))
                ->get()
                ->filter(fn (User $u) => $u->isSuperAdmin() || ($buildingId && in_array($buildingId, $u->rootBuildingIds(), true)))
                ->values();

            if ($recipients->isEmpty()) {
                return;
            }

            $reason = $skippedPartials->isNotEmpty()
                ? 'do hoá đơn tháng ' . $skippedPartials->map(fn (Invoice $i) => $i->month?->format('m/Y'))->implode(', ') . ' đang "Thanh toán 1 phần" nên không tự trả thêm được'
                : 'không rõ nguyên nhân, cần đối chiếu lại nợ tại thời điểm giao dịch';

            app(AdminNotificationService::class)->notify(
                $recipients,
                'Cần đối soát thanh toán',
                'Hoá đơn #' . $currentInvoice->id . ': còn dư ' . number_format($unallocated, 0, ',', '.') . 'đ chưa gán được vào hoá đơn nào — ' . $reason . '. Vui lòng kiểm tra và ghi nhận tay.',
                [
                    'type'             => 'minihouse_invoice_unallocated',
                    'module'           => 'minihouse',
                    'invoice_id'       => $currentInvoice->id,
                    'unallocated'      => $unallocated,
                    'skipped_invoice_ids' => $skippedPartials->pluck('id')->all(),
                ],
                'heroicon-o-exclamation-triangle',
                'warning',
            );
        } catch (\Throwable $e) {
            Log::warning('InvoicePaymentAllocationService: gửi cảnh báo unallocated thất bại', [
                'invoice_id' => $currentInvoice->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
