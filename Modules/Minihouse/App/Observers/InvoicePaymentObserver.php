<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Transaction;

// Đồng bộ lại Invoice.amount_paid/paid_at/status (cột CACHE, giống hệt cách Invoice.service_amount
// cache từ InvoiceItem) mỗi khi 1 dòng thanh toán được thêm/sửa/xoá — không tính tay trong
// InvoiceForm vì Repeater 'payments' có thể thêm/xoá dòng độc lập, cần 1 chỗ luôn đúng bất kể sửa
// từ đâu (form, tinker, import...).
//
// ĐỒNG THỜI tự tạo/cập nhật 1 dòng "Thu" tương ứng trong sổ Thu Chi (Transaction) — trước đây ghi
// nhận thanh toán hoá đơn và sổ Thu Chi là 2 nơi hoàn toàn tách biệt, nhân viên phải tạo tay thêm 1
// lần nữa ở Thu Chi, dễ quên/ghi trùng/ghi lệch số tiền. Liên kết 1-1 qua
// Transaction.invoice_payment_id để sửa/xoá 1 lần thanh toán thì dòng Thu Chi tương ứng tự cập nhật
// theo (xoá thì DB tự cascade — xem migration), không tạo trùng lặp mỗi lần đồng bộ lại.
class InvoicePaymentObserver
{
    public function saved(InvoicePayment $payment): void
    {
        $this->resyncInvoice($payment->invoice_id);
        $this->syncTransaction($payment);
    }

    public function deleted(InvoicePayment $payment): void
    {
        $this->resyncInvoice($payment->invoice_id);
        // Không cần tự xoá Transaction ở đây — FK invoice_payment_id đã cascadeOnDelete().
    }

    private function resyncInvoice(?int $invoiceId): void
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            return;
        }

        $amountPaid = (float) $invoice->payments()->sum('amount');
        $lastPaidAt = $invoice->payments()->latest('paid_at')->value('paid_at');

        $status = Invoice::STATUS_UNPAID;

        if ($amountPaid > 0 && $amountPaid < (float) $invoice->total_amount) {
            $status = Invoice::STATUS_PARTIAL;
        } elseif ($amountPaid >= (float) $invoice->total_amount && (float) $invoice->total_amount > 0) {
            $status = Invoice::STATUS_PAID;
        }

        // updateQuietly() — tránh gọi lại observer của chính Invoice (hiện chưa có, nhưng giữ quy
        // ước an toàn chung của cả module khi ghi đè cột cache từ 1 model khác).
        $invoice->updateQuietly([
            'amount_paid' => $amountPaid,
            'paid_at'     => $lastPaidAt,
            'status'      => $status,
        ]);
    }

    private function syncTransaction(InvoicePayment $payment): void
    {
        $invoice = $payment->invoice()->with('contract.room')->first();

        if (! $invoice) {
            return;
        }

        $room = $invoice->contract?->room;

        // $room có thể null nếu hợp đồng/phòng đã bị xoá — không ghi đè building_id đã có (nếu dòng
        // Transaction này đã tồn tại từ trước) thành NULL, vì Transaction lọc theo toà nhà bằng
        // whereIn('building_id', ...) nên NULL sẽ làm dòng "Thu" này biến mất khỏi mọi tài khoản bị
        // giới hạn quản lý theo toà (kể cả người vừa ghi nhận thanh toán này).
        $buildingId = $room?->building_id
            ?? Transaction::where('invoice_payment_id', $payment->id)->value('building_id');

        Transaction::updateOrCreate(
            ['invoice_payment_id' => $payment->id],
            [
                'contract_id'      => $invoice->contract_id,
                'building_id'      => $buildingId,
                'type'             => Transaction::TYPE_IN,
                'amount'           => $payment->amount,
                'transaction_date' => $payment->paid_at,
                'note'             => sprintf(
                    'Thanh toán hoá đơn tháng %s - phòng %s',
                    $invoice->month?->format('m/Y'),
                    $room?->code ?? '—',
                ),
            ]
        );
    }
}
