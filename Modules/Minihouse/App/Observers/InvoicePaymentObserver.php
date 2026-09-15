<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Reminder;
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

    // public — InvoiceController::update() cũng gọi lại đúng hàm này sau khi nhân viên sửa số tiền
    // hoá đơn (room_price/điện/nước/phụ thu), để status/amount_paid được TÍNH LẠI ngay theo tổng mới
    // (xem giải thích đầy đủ ở InvoiceController::update()).
    public function resyncInvoice(?int $invoiceId): void
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            return;
        }

        // CHỈ tính khoản đã được duyệt (status='approved') — khoản 'pending' (nhân viên vừa ghi
        // nhận, chưa được Chủ toà nhà xác nhận) không được coi là "đã thanh toán" để tránh nhân viên
        // tự khai đã thu tiền mà chưa ai kiểm chứng (xem InvoicePayment::STATUS_*).
        $approvedPayments = $invoice->payments()->where('status', InvoicePayment::STATUS_APPROVED);
        $amountPaid = (float) (clone $approvedPayments)->sum('amount');
        $lastPaidAt = (clone $approvedPayments)->latest('paid_at')->value('paid_at');

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

        // Hoá đơn vừa chuyển "Đã thanh toán" — tự đánh dấu xong mọi "Nhắc đóng tiền" đang gắn hoá
        // đơn này (nếu còn), để: 1) không hiện nhầm là "chưa xử lý" trên danh sách nhắc việc nữa, và
        // 2) dừng hẳn việc lặp lại nhắc (xem SendReminderNotificationsCommand::dueForRepeat() — vốn
        // đã tự dừng qua điều kiện trạng thái hoá đơn, đây chỉ thêm để danh sách Nhắc việc phản ánh
        // đúng thực tế, không cần nhân viên tự tay tắt).
        if ($status === Invoice::STATUS_PAID) {
            Reminder::withoutGlobalScopes()
                ->where('invoice_id', $invoice->id)
                ->where('is_done', false)
                ->update(['is_done' => true]);
        }
    }

    private function syncTransaction(InvoicePayment $payment): void
    {
        // KHÔNG tự tạo dòng "Thu" cho khoản CHƯA duyệt — sổ Thu Chi chỉ nên phản ánh tiền đã CHẮC
        // CHẮN thu được (đã duyệt hoặc PayOS tự xác nhận), không phải lời khai chưa kiểm chứng.
        if (! $payment->isApproved()) {
            return;
        }

        $invoice = $payment->invoice()->first();

        if (! $invoice) {
            return;
        }

        // Contract dùng SoftDeletes riêng — quan hệ mặc định $invoice->contract sẽ trả về null nếu
        // hợp đồng bị xoá mềm, làm mất building_id (dòng "Thu" biến mất khỏi mọi tài khoản bị giới
        // hạn theo toà) — cùng lỗi lớp đã gặp và sửa ở Invoice/ContractPrintController.
        $contract = $invoice->contract_id
            ? Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->find($invoice->contract_id)
            : null;
        $room = $contract?->room;

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
