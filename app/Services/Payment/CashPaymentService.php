<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Models\User;
use App\Services\PartnerNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Payment\Entities\Order;
use Modules\Payment\Http\Controllers\PaymentController;
use RuntimeException;

/**
 * Thanh toán TIỀN MẶT tại quầy (payment_method = cod, người thu = "cash" — đối tác giữ tiền, nộp hoa hồng ở kỳ đối soát).
 *
 *  - Nhân viên bấm "Xác nhận đã thu tiền mặt" (CMS / API) → đơn chuyển "paid" qua CÙNG nhánh với cổng online (gán mã cổng, báo khách), ghi ai thu,
 *    lúc nào, ghi chú. Đơn "paid" thì hoa hồng được chốt và vào kỳ đối soát như mọi đơn đối tác tự thu.
 *  - Lệnh orders:cash-sweep (mỗi 10 phút):
 *      · tới giờ nhận phòng mà chưa thu tiền, khách chưa nhận phòng → nhắc đối tác (1 lần);
 *      · quá giờ nhận phòng cash_payment.no_show_hours giờ (chỉnh ở CMS) mà khách vẫn chưa nhận phòng → tự huỷ (khách không đến), nhả phòng;
 *      · khách ĐÃ nhận phòng mà chưa xác nhận thu tiền → không huỷ; hết kỳ lưu trú + settlement.auto_complete_hours giờ thì tự coi là đã thu đủ
 *        (đơn vào đối soát, báo đối tác + Super Admin) — tránh đơn đối tác đã giữ tiền mà không bao giờ vào đối soát. Khách thật sự không trả thì
 *        đối tác khiếu nại ở bảng đối soát.
 */
class CashPaymentService
{
    public const SOURCE_STAFF = 'staff';

    public const SOURCE_AUTO_STAYED = 'auto_stayed';

    /** Đơn có đang chờ thu tiền mặt không (nút xác nhận chỉ hiện khi đúng). */
    public function awaitingCash(Order $order): bool
    {
        return $order->payment_method === BookingPaymentPolicy::CASH && $order->status === 'pending';
    }

    /**
     * Xác nhận đã thu đủ tiền mặt của đơn. $by null = hệ thống tự xác nhận.
     *
     * @throws ValidationException đơn không phải tiền mặt / không còn chờ thu
     */
    public function confirm(Order $order, ?User $by, ?string $note = null, string $source = self::SOURCE_STAFF): Order
    {
        if ($order->payment_method !== BookingPaymentPolicy::CASH) {
            throw ValidationException::withMessages(['order' => 'Đơn này không thanh toán tiền mặt tại quầy.']);
        }

        return DB::transaction(function () use ($order, $by, $note, $source) {
            $locked = Order::withoutGlobalScopes()->with('items')->lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Đơn không còn chờ thu tiền (trạng thái: ' . $locked->status . ').']);
            }

            $locked->forceFill([
                'cash_confirmed_at' => now(), 'cash_confirmed_by' => $by?->id, 'cash_confirm_source' => $source,
                'cash_confirm_note' => filled($note) ? mb_substr(trim((string) $note), 0, 255) : null,
            ])->saveQuietly();

            if (! app(PaymentController::class)->processCashPayment($locked)) {
                throw new RuntimeException('Không cập nhật được đơn sau khi xác nhận thu tiền mặt (xem log).');
            }

            Log::info('Xác nhận thu tiền mặt', ['order_id' => $locked->id, 'order_code' => $locked->order_code, 'by' => $by?->id, 'source' => $source]);

            return $locked->fresh();
        });
    }

    /** Giờ nhận phòng sớm nhất / giờ trả phòng muộn nhất của đơn. @return array{0: ?Carbon, 1: ?Carbon} */
    public function stay(Order $order): array
    {
        $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
        $in = $items->pluck('checkin_date')->filter()->min();
        $out = $items->pluck('checkout_date')->filter()->max();

        return [$in ? Carbon::parse($in) : null, $out ? Carbon::parse($out) : null];
    }

    public function arrived(Order $order): bool
    {
        return in_array($order->order_status, ['checked_in', 'checked_out'], true) || $order->checked_in_at !== null;
    }

    /** @return array{reminded: int, cancelled: int, auto_confirmed: int} */
    public function sweep(): array
    {
        $done = ['reminded' => 0, 'cancelled' => 0, 'auto_confirmed' => 0];
        $noShowHours = max(1, (int) \App\Support\CashPaymentConfig::get('no_show_hours', 3));
        $completeHours = max(0, (int) config('settlement.auto_complete_hours', 12));

        Order::withoutGlobalScopes()->with('items')->where('payment_method', BookingPaymentPolicy::CASH)->where('status', 'pending')
            ->orderBy('id')->chunkById(200, function ($orders) use (&$done, $noShowHours, $completeHours) {
                foreach ($orders as $order) {
                    try {
                        [$in, $out] = $this->stay($order);

                        if (! $in) {
                            continue;
                        }

                        if ($this->arrived($order)) {
                            if ($out && $out->copy()->addHours($completeHours)->isPast()) {
                                $this->autoConfirmStayed($order);
                                $done['auto_confirmed']++;
                            }

                            continue;
                        }

                        if ($in->copy()->addHours($noShowHours)->isPast()) {
                            // Đơn cũ đã qua hết kỳ ở (tồn từ trước khi có lệnh này): huỷ im lặng, không báo khách / đối tác chuyện đã qua.
                            $this->cancelNoShow($order, $noShowHours, notify: ! ($out && $out->isPast()));
                            $done['cancelled']++;
                        } elseif ($in->isPast() && $order->cash_reminded_at === null) {
                            $this->remind($order, $in, $noShowHours);
                            $done['reminded']++;
                        }
                    } catch (\Throwable $e) {
                        Log::error('Quét đơn tiền mặt lỗi', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $done;
    }

    private function remind(Order $order, Carbon $checkin, int $noShowHours): void
    {
        $order->forceFill(['cash_reminded_at' => now()])->saveQuietly();

        $this->notifyPartner($order, 'cash_payment_reminder', 'Đơn tiền mặt chưa thu — tới giờ nhận phòng',
            'Đơn #' . $order->order_code . ' (' . $order->buyer_name . ') tới giờ nhận phòng ' . $checkin->format('H:i d/m') . ' mà chưa xác nhận thu tiền mặt. '
            . 'Khách đến thì thu tiền và bấm "Xác nhận đã thu tiền mặt"; khách chưa nhận phòng sau ' . $noShowHours . ' giờ thì đơn tự huỷ để nhả phòng.');
    }

    private function cancelNoShow(Order $order, int $noShowHours, bool $notify = true): void
    {
        // Nguồn huỷ để đếm tỷ lệ huỷ (CancellationGuardService qua OrderObserver).
        $order->cancel_source = CancellationGuardService::SOURCE_NO_SHOW;
        $order->update(['status' => 'failed']);

        try {
            app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)->releaseCode($order->id);
            \App\Console\Commands\ExpirePaymentOrders::broadcastRelease(app(\App\Services\SlotRealtimeService::class), $order->items->first()?->product, $order->items);
        } catch (\Throwable $e) {
            Log::warning('Huỷ đơn tiền mặt không đến: nhả mã cổng / phát realtime lỗi', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        Log::info('Huỷ đơn tiền mặt — khách không đến', ['order_id' => $order->id, 'order_code' => $order->order_code, 'notify' => $notify]);

        if (! $notify) {
            return;
        }

        $this->notifyPartner($order, 'cash_no_show_cancelled', 'Đã huỷ đơn tiền mặt — khách không đến',
            'Đơn #' . $order->order_code . ' (' . $order->buyer_name . ') quá giờ nhận phòng ' . $noShowHours . ' giờ mà khách chưa nhận phòng và chưa thu tiền nên đã tự huỷ, phòng được mở bán lại.');

        try {
            $title = 'Đơn đặt phòng đã bị huỷ';
            $body = 'Đơn #' . $order->order_code . ' đã bị huỷ do quá giờ nhận phòng mà chưa nhận phòng / thanh toán tại quầy.';
            $notify = app(\App\Services\NotificationFcmService::class);

            if ($order->customer_id && ($customer = $order->customer)) {
                $notify->sendToCustomer($customer, $title, $body, 'order_cancelled', ['order_code' => (string) $order->order_code]);
            } elseif ($order->device_token) {
                $notify->sendToGuestToken($order->device_token, $title, $body, 'order_cancelled', ['order_code' => (string) $order->order_code]);
            }
        } catch (\Throwable $e) {
            Log::warning('Huỷ đơn tiền mặt: báo khách lỗi', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    private function autoConfirmStayed(Order $order): void
    {
        $this->confirm($order, null, 'Hệ thống tự xác nhận: khách đã nhận phòng và hết kỳ lưu trú mà đơn chưa được xác nhận thu tiền mặt.', self::SOURCE_AUTO_STAYED);

        $body = 'Đơn #' . $order->order_code . ' (' . $order->buyer_name . ') đã nhận phòng và hết kỳ lưu trú nhưng chưa được xác nhận thu tiền mặt, nên hệ thống tự coi là đã thu đủ '
            . number_format((int) $order->amount, 0, ',', '.') . 'đ và đưa vào kỳ đối soát. Nếu khách thực sự chưa trả, gửi khiếu nại ở bảng đối soát.';

        $this->notifyPartner($order, 'cash_auto_confirmed', 'Đơn tiền mặt được tự xác nhận đã thu', $body);

        try {
            app(\App\Services\AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                'Đơn tiền mặt được tự xác nhận đã thu',
                $body,
                ['type' => 'cash_auto_confirmed', 'order_code' => (string) $order->order_code, 'partner_id' => $order->partner_id],
                'heroicon-o-banknotes',
                'warning',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyPartner(Order $order, string $type, string $title, string $body): void
    {
        $partnerId = PayOsAccountResolver::partnerIdForOrder($order);
        $partner = $partnerId ? Partner::withTrashed()->find($partnerId) : null;

        if ($partner) {
            app(PartnerNotifier::class)->send($partner, $type, $title, $body, ['order_code' => (string) $order->order_code], 'warning', false);
        }
    }
}
