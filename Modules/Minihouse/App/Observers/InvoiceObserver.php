<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Invoice;

// Invoice dùng SoftDeletes (deleted_at) — cascadeOnDelete() ở FK minihouse_invoice_payments chỉ
// chạy khi có DELETE thật, không chạy khi soft-delete (chỉ là UPDATE deleted_at). Nếu không tự xoá
// tay ở đây, xoá mềm 1 hoá đơn nhầm/trùng vẫn để lại InvoicePayment + dòng "Thu" tự động tương ứng
// trong sổ Thu Chi (Transaction) — thu tiền vẫn hiện dù hoá đơn gốc đã "biến mất" khỏi mọi báo cáo
// theo Invoice, làm lệch số liệu. InvoicePayment không dùng SoftDeletes nên gọi delete() ở đây là
// xoá THẬT, đúng ý xoá hẳn luôn cả lịch sử thanh toán của hoá đơn bị xoá — kéo theo cascade xoá
// đúng dòng Transaction liên kết qua invoice_payment_id.
class InvoiceObserver
{
    public function deleting(Invoice $invoice): void
    {
        $invoice->payments()->get()->each->delete();
    }
}
