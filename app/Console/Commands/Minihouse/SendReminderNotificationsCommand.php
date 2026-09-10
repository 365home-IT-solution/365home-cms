<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Services\ReminderNotificationService;

// Gửi thông báo (chuông + bảng "notifications" dùng chung với Home, cùng Zalo ZNS) cho các Reminder
// đến hạn — có 2 nhóm:
//  1. CHƯA TỪNG GỬI, đã tới/qua remind_date, chưa xong (is_done=false) — hành vi gốc, gửi đúng 1 lần.
//  2. ĐÃ GỬI rồi nhưng là "Nhắc đóng tiền" gắn 1 hoá đơn VẪN CÒN CHƯA THANH TOÁN sau khi đã đủ số
//     ngày lặp lại cấu hình ở Building.payment_reminder_repeat_days (VD nhắc mùng 5, 3 ngày sau vẫn
//     chưa thấy thanh toán thì tự nhắc lại, lặp mỗi 3 ngày) — CHỈ DỪNG khi hoá đơn đã "Đã thanh toán"
//     (InvoicePaymentObserver tự đánh dấu is_done=true cho các reminder liên quan lúc đó, xem
//     InvoicePaymentObserver::markLinkedRemindersDone(), nên nhóm 2 tự loại các reminder đã xong).
// Chạy hàng ngày (xem app/Console/Kernel.php).
class SendReminderNotificationsCommand extends Command
{
    protected $signature = 'minihouse:send-reminder-notifications';

    protected $description = 'Gửi thông báo cho các nhắc việc MiniHouse đến hạn/quá hạn (và nhắc đóng tiền lặp lại nếu vẫn chưa thanh toán)';

    public function handle(): int
    {
        $newReminders = Reminder::query()
            ->withoutGlobalScopes()
            ->where('is_done', false)
            ->whereNull('notified_at')
            ->whereDate('remind_date', '<=', now()->toDateString())
            ->get();

        $repeatReminders = $this->dueForRepeat();

        $all = $newReminders->concat($repeatReminders);

        foreach ($all as $reminder) {
            ReminderNotificationService::notify($reminder);
        }

        $this->info("Đã gửi thông báo cho {$all->count()} nhắc việc ({$newReminders->count()} mới, {$repeatReminders->count()} lặp lại do chưa thanh toán).");

        return self::SUCCESS;
    }

    // Nhắc đóng tiền ĐÃ GỬI ít nhất 1 lần, hoá đơn liên quan VẪN chưa thanh toán, và đã đủ số ngày
    // lặp lại kể từ lần nhắc gần nhất — tính theo Building.payment_reminder_repeat_days của đúng
    // toà nhà chứa hợp đồng đó (không cấu hình = không lặp, giữ nguyên hành vi gốc).
    private function dueForRepeat()
    {
        return Reminder::query()
            ->withoutGlobalScopes()
            ->where('is_done', false)
            ->where('type', Reminder::TYPE_PAYMENT)
            ->whereNotNull('invoice_id')
            ->whereNotNull('notified_at')
            ->get()
            ->filter(function (Reminder $reminder) {
                $invoice = Invoice::withoutGlobalScopes()->find($reminder->invoice_id);

                if (! $invoice || ! in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true)) {
                    return false;
                }

                $repeatDays = $reminder->resolveBuildingId()
                    ? \Modules\Minihouse\App\Models\Building::withoutGlobalScopes()->find($reminder->resolveBuildingId())?->payment_reminder_repeat_days
                    : null;

                if (! $repeatDays) {
                    return false;
                }

                return $reminder->notified_at->copy()->addDays($repeatDays)->lte(now());
            });
    }
}
