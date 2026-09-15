<?php

namespace Modules\Minihouse\App\Services;

use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Reminder;

// Tách ra từ EditContract (Filament) — dùng CHUNG cho cả trang Sửa hợp đồng LẪN API
// (ContractController::checkout/cancel/transferRoom), tránh 2 nơi tự viết lại cùng 1 logic rồi lệch
// nhau (đã từng xảy ra: bản API port sau thiếu hẳn bước rút ngắn/lập bù hoá đơn này, gây thu thừa/
// thiếu tiền khi khách trả phòng giữa tháng qua API — xem lịch sử sửa lỗi).
//
// Khách trả phòng/huỷ hợp đồng/chuyển phòng GIỮA THÁNG (không đúng ngày cuối tháng) thì hoá đơn tháng
// hiện tại (nếu đã lập) đang tính tiền phòng cho CẢ THÁNG dù khách không ở hết — phải rút ngắn lại
// đúng số ngày ở thật. Đồng thời có thể còn khoảng ngày ĐÃ Ở nhưng CHƯA có hoá đơn nào che phủ (VD
// giữa 2 lần lập hoá đơn hàng loạt) — phải tự lập bù, không được để mất trắng doanh thu những ngày đó.
class ContractEarlyEndService
{
    // @return float Tổng chênh lệch (total_amount mới − amount_paid, cộng dồn qua mọi hoá đơn bị rút
    //                ngắn, CỘNG THÊM tổng hoá đơn mới tạo ra để bù khoảng trống chưa lập) — DƯƠNG =
    //                còn thiếu thêm (hoá đơn mới tạo ra bù khoảng trống luôn dương vì chưa ai trả),
    //                ÂM = đã thu DƯ — người gọi tự quyết định hoàn tiền mặt hay trừ vào hợp đồng mới,
    //                hàm này không tự động chuyển tiền.
    public static function reprorate(Contract $contract, Carbon $checkoutAt, bool $preview = false): float
    {
        $lastNightOccupied = $checkoutAt->copy()->subDay();

        // --- Chiều A: rút ngắn hoá đơn ĐÃ LẬP đang tính quá ngày trả phòng ---
        $invoices = Invoice::where('contract_id', $contract->id)
            ->whereDate('period_end', '>=', $checkoutAt->toDateString())
            ->get();

        $netDelta = 0.0;

        foreach ($invoices as $invoice) {
            $occupiedAnyDay = $lastNightOccupied->gte($invoice->period_start);

            if ($occupiedAnyDay) {
                $daysInPeriod = $invoice->period_start->diffInDays($lastNightOccupied) + 1;
                $daysInMonth  = $invoice->period_start->daysInMonth;
                $newRoomPrice = $daysInPeriod >= $daysInMonth
                    ? (float) $contract->monthly_price
                    : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 0);
            } else {
                // Không ở ngày nào trong kỳ hoá đơn này (VD chuyển phòng NGAY ngày dọn vào) — tiền
                // phòng = 0, chỉ còn phụ thu (nếu có) là hợp lệ.
                $newRoomPrice = 0.0;
            }

            $newTotal = round($newRoomPrice + (float) $invoice->service_amount, 0);
            $netDelta += $newTotal - (float) $invoice->amount_paid;

            if ($preview) {
                continue;
            }

            $newStatus = match (true) {
                (float) $invoice->amount_paid <= 0             => Invoice::STATUS_UNPAID,
                (float) $invoice->amount_paid < $newTotal       => Invoice::STATUS_PARTIAL,
                default                                          => Invoice::STATUS_PAID,
            };

            $invoice->update([
                'room_price'   => $newRoomPrice,
                'total_amount' => $newTotal,
                'status'       => $newStatus,
                ...($occupiedAnyDay ? ['period_end' => $lastNightOccupied] : []),
            ]);

            // $invoice->update() ở đây KHÔNG đi qua InvoicePaymentObserver (chỉ lắng nghe sự kiện
            // của InvoicePayment, không phải Invoice) — nên phần "tự đánh dấu xong Nhắc đóng tiền khi
            // hoá đơn chuyển Đã thanh toán" của observer đó KHÔNG tự chạy ở đây. Thiếu bước này thì
            // 1 hoá đơn đã co ngắn lại vừa đủ số tiền đã trả (VD khách trả phòng sớm, tiền đã đóng
            // cho cả tháng giờ vừa khít số ngày ở thật) vẫn còn 1 "Nhắc đóng tiền" treo is_done=false,
            // tiếp tục nhắc Zalo/SMS/Portal cho 1 hoá đơn đã xong xuôi — lặp lại đúng logic
            // InvoicePaymentObserver::resyncInvoice() làm thủ công tại đây.
            if ($newStatus === Invoice::STATUS_PAID) {
                Reminder::withoutGlobalScopes()
                    ->where('invoice_id', $invoice->id)
                    ->where('is_done', false)
                    ->update(['is_done' => true]);
            }
        }

        // --- Chiều B: lập bù hoá đơn cho khoảng ngày ĐÃ Ở THẬT nhưng CHƯA có hoá đơn nào che phủ ---
        // "Đã che phủ tới đâu" = period_end lớn nhất trong TOÀN BỘ hoá đơn của hợp đồng (không chỉ
        // các hoá đơn vừa rút ngắn ở trên) — nếu chưa có hoá đơn nào thì tính từ ngày bắt đầu hợp
        // đồng. Còn khoảng trống TRƯỚC ngày trả phòng thì đây chính là những ngày khách đã ở nhưng
        // chưa được lập hoá đơn — phải tự lập bù, không được để mất trắng.
        $latestCoveredEnd = Invoice::where('contract_id', $contract->id)->max('period_end');
        $gapStart = $latestCoveredEnd ? Carbon::parse($latestCoveredEnd)->addDay() : $contract->start_date->copy();

        if ($gapStart->lte($lastNightOccupied)) {
            $gapTotal = null;

            if ($preview) {
                // Không ghi CSDL ở chế độ preview — chỉ ước lượng tiền phòng của khoảng trống bằng
                // đúng công thức InvoiceGenerationService dùng (không tạo hoá đơn thật để tính thử).
                $daysInPeriod = $gapStart->diffInDays($lastNightOccupied) + 1;
                $daysInMonth  = $gapStart->daysInMonth;
                $gapTotal = $daysInPeriod >= $daysInMonth
                    ? (float) $contract->monthly_price
                    : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 0);
            } else {
                $gapInvoice = InvoiceGenerationService::buildInvoiceForContract($contract, $gapStart, $lastNightOccupied);
                $gapTotal   = (float) $gapInvoice->total_amount;
            }

            // Hoá đơn bù luôn CHƯA ai trả (amount_paid=0) — toàn bộ tính vào "còn thiếu".
            $netDelta += $gapTotal;
        }

        return $netDelta;
    }
}
