<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\PortalNotification;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Services\PortalNotificationService;

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

    // Tự tạo "Nhắc đóng tiền" ngay khi hoá đơn được lập (dù qua cron, nút "Lập hoá đơn hàng loạt",
    // hay tạo tay 1 hoá đơn) — CHỈ khi toà nhà đã cấu hình payment_reminder_days_before (mặc định
    // NULL = tắt, không đổi hành vi cũ, nhân viên vẫn tạo tay như trước). Hạn nhắc = ngày đến hạn
    // thật của hoá đơn (period_start — mùng 1 với toà "Theo tháng dương lịch"/đúng ngày dọn vào với
    // toà "Theo ngày thuê", HOẶC "Ngày thu cố định" nếu toà "Theo tháng dương lịch" có khai riêng,
    // xem Building::fixed_due_day) trừ đi số ngày đã cấu hình. Gắn invoice_id để tin nhắc kèm đủ chi
    // tiết tiền phòng/điện/nước khi gửi Zalo (xem MinihouseZaloService::buildInvoiceTemplateData())
    // giống hệt reminder tạo tay.
    public function created(Invoice $invoice): void
    {
        // withoutGlobalScopes() — hợp đồng/phòng vừa dùng để lập hoá đơn này thường đang active, khó
        // xảy ra bị xoá mềm ngay lúc này, nhưng giữ cùng quy ước an toàn đã áp dụng xuyên suốt module
        // (tránh lặp lại lớp lỗi SoftDeletes đã gặp nhiều lần ở Invoice/Contract/Reminder).
        $contract = $invoice->contract_id
            ? Contract::withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])->find($invoice->contract_id)
            : null;

        // Gán thẳng quan hệ đã tự resolve an toàn ở trên, để Invoice::dueDate() dùng lại được (qua
        // $this->contract) mà không phải tự query lại lần 2 hay lặp logic tính ngày đến hạn ở đây.
        $invoice->setRelation('contract', $contract);

        // Báo TRONG PORTAL ngay khi hoá đơn được lập — NHƯNG chỉ khi đã đủ thông tin cho khách xem
        // (Invoice::isReadyForTenant() — xem giải thích đầy đủ ở đó). "Lập hoá đơn hàng loạt" cố ý
        // tạo hoá đơn THIẾU chỉ số điện/nước để nhân viên bổ sung sau; báo/hiện ngay lúc này cho
        // khách sẽ lộ ra 1 hoá đơn còn thiếu tiền điện/nước, rồi tổng tiền lại tự đổi khi nhân viên
        // điền xong — khách dễ hiểu nhầm là hệ thống tính sai. Trường hợp tạo tay đã điền đủ ngay từ
        // đầu thì vẫn báo được luôn (isReadyForTenant() true ngay từ lúc created()); còn thiếu thì
        // chờ updated() bên dưới bắn khi đủ. ĐỘC LẬP với việc toà có cấu hình "nhắc đóng tiền trước X
        // ngày" hay không — 2 việc khác nhau.
        if ($invoice->contract_id && $invoice->isReadyForTenant()) {
            $this->notifyPortalInvoiceReady($invoice, $contract);
        }

        $daysBefore = $contract?->room?->building?->payment_reminder_days_before;
        $dueDate    = $invoice->dueDate();

        if (! $daysBefore || ! $dueDate) {
            return;
        }

        Reminder::create([
            'title'       => 'Nhắc đóng tiền — phòng ' . ($contract?->room?->code ?? '—') . ' tháng ' . $invoice->month?->format('m/Y'),
            'type'        => Reminder::TYPE_PAYMENT,
            'contract_id' => $invoice->contract_id,
            'invoice_id'  => $invoice->id,
            'remind_date' => $dueDate->copy()->subDays($daysBefore),
        ]);
    }

    // Hoá đơn tạo lúc "Lập hoá đơn hàng loạt" còn THIẾU điện/nước sẽ không báo Portal ở created() ở
    // trên — bắt đúng thời điểm nhân viên SỬA hoá đơn điền nốt 2 chỉ số đó (chuyển từ "chưa đủ" sang
    // "đủ") để báo Portal ĐÚNG 1 LẦN tại đây. wasChanged() + so getOriginal() để không báo lại nếu
    // hoá đơn ĐÃ đủ từ trước rồi mà nhân viên chỉ sửa gì đó không liên quan (VD sửa phụ thu).
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->contract_id || ! $invoice->wasChanged(['electric_end', 'water_end'])) {
            return;
        }

        $wasReadyBefore = filled($invoice->getOriginal('electric_end')) && filled($invoice->getOriginal('water_end'));

        if ($wasReadyBefore || ! $invoice->isReadyForTenant()) {
            return;
        }

        $contract = Contract::withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'room.building' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($invoice->contract_id);

        $this->notifyPortalInvoiceReady($invoice, $contract);
    }

    private function notifyPortalInvoiceReady(Invoice $invoice, ?Contract $contract): void
    {
        PortalNotificationService::notifyContractTenants(
            $invoice->contract_id,
            PortalNotification::TYPE_INVOICE_NEW,
            'Hoá đơn mới — tháng ' . $invoice->month?->format('m/Y'),
            'Phòng ' . ($contract?->room?->code ?? '—') . ' — tổng tiền ' . number_format((float) $invoice->total_amount, 0, ',', '.') . 'đ.',
            '/minihouse/portal/invoices/' . $invoice->id,
        );
    }
}
