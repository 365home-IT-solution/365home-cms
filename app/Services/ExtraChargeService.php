<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;
use PayOS\PayOS;

class ExtraChargeService
{
    // ─── Tính chênh lệch giá ────────────────────────────────────────────────

    /**
     * Tính chênh lệch giá sau khi admin cập nhật guest_count / services / khung giờ (thêm/bớt
     * order_item — vd đặt thêm 1 khung giờ, hoặc bỏ bớt 1 khung giờ đã đặt).
     *
     * QUAN TRỌNG: so sánh với full_amount + settled_adjustment_total, KHÔNG chỉ full_amount không —
     * full_amount CỐ Ý giữ nguyên vĩnh viễn (để "Khách thanh toán đầy đủ" trong Lịch sử thanh toán
     * luôn hiện đúng số tại THỜI ĐIỂM đó, không bị đội lên sau này), nhưng nếu chỉ so với
     * full_amount thôi thì mỗi lần phát sinh MỚI sẽ bị CỘNG LẶP LẠI cả những khoản phát sinh đã
     * XÁC NHẬN THU/HOÀN xong ở lần trước (đã xác nhận thực tế: thêm khung 1 +200k → xác nhận thu →
     * thêm khung 2 (+200k nữa) lại hiện SAI "+400k" thay vì đúng "+200k", vì hệ thống quên mất
     * 200k đầu đã thu rồi). settled_adjustment_total lưu tổng các khoản ĐÃ XÁC NHẬN xong (dương =
     * đã thu thêm, âm = đã hoàn khách — xem markExtraChargeAsCash/markExtraChargeAsTransfer/
     * handleExtraChargePaid/markRefundAsDone), trừ thêm vào đây để mỗi lần chỉ tính đúng phần MỚI
     * phát sinh kể từ lần xác nhận gần nhất.
     *
     * calculateRealTotal() dùng ĐÚNG 1 công thức duy nhất, khớp OrderForm::computeOrderTotal()
     * (dùng cho form admin) và total-amount-card.blade.php (card client) — 3 nơi PHẢI cùng ra 1 số.
     * KHÔNG cộng 'surcharge' (phụ thu gõ tay) vào calculateRealTotal() vì lý do tương tự —
     * computeOrderTotal() cũng KHÔNG gồm surcharge (cộng RIÊNG ở OrderForm::calculateTotal()), nên
     * cộng surcharge NGAY TẠI ĐÂY (chỉ ảnh hưởng công thức chênh lệch, không đụng
     * calculateRealTotal() dùng chung cho cột "Tổng tiền"/card client) để phụ thu gõ tay trên đơn
     * đã paid/deposit cũng tự động tính vào khoản phát sinh/hoàn tiền giống hệt đổi phòng/dịch vụ/
     * số khách — xem EditOrder::beforeSave() ($oldSurcharge) / afterSave() ($surchargeChanged).
     *
     * @param  Order  $order  Đơn đã refresh với relations (items.product, services)
     * @return int    Dương = khách còn nợ thêm (thêm khung giờ/phụ thu), âm = cần hoàn tiền (bớt khung giờ/giảm phụ thu)
     */
    public function calculateDiff(Order $order): int
    {
        return $this->calculateRealTotal($order)
            + (int) ($order->surcharge ?? 0)
            - (int) ($order->full_amount ?? 0)
            - (int) ($order->settled_adjustment_total ?? 0);
    }

    /**
     * Tính TỔNG THẬT của đơn — dùng chung OrderForm::resolveProductGroupPricing() (port chính xác
     * BuildsRoomBooking::computeBookingPreview(), engine tính giá THẬT dùng cho mọi đơn tạo/sửa
     * qua API — /api/admin/orders/preview) để ra ĐÚNG giá phòng + khuyến mãi/giảm giá hệ thống +
     * phụ thu khách cho từng nhóm product, cộng thêm tổng dịch vụ thêm.
     */
    public function calculateRealTotal(Order $order): int
    {
        // Dùng lại collection ĐÃ eager-load (items.product) nếu có — vd OrderTable dùng hàm này để
        // tính cột "Tổng tiền" cho MỖI dòng trong danh sách đơn, gọi ->items()->get() (query MỚI,
        // bỏ qua items.product đã eager-load từ trước) sẽ gây N+1 nặng (2 query/dòng × số dòng
        // hiển thị). Chỉ tự query lại khi thực sự chưa có sẵn (vd gọi trực tiếp trên 1 đơn lẻ).
        $items = $order->relationLoaded('items')
            ? $order->items->where('extra_fee', 0)->values()
            : $order->items()->where('extra_fee', 0)->get();

        $total          = 0;
        $itemsByProduct = $items->filter(fn ($item) => $item->product_id)->groupBy('product_id');

        foreach ($itemsByProduct as $groupItems) {
            $product = $groupItems->first()->product;

            // Giá phòng (đã áp khuyến mãi/giảm giá hệ thống) + phụ thu khách — dùng chung 1 công
            // thức với OrderForm::computeOrderTotal()/total-amount-card.blade.php (3 nơi PHẢI
            // cùng ra 1 số, xem OrderForm::resolveProductGroupPricing()). OrderItem model hỗ trợ
            // ArrayAccess ($item['price']/['checkin_date']...) nên dùng thẳng được, chỉ cần
            // ->all() để khớp type-hint array của hàm dùng chung.
            $pricing = \Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm::resolveProductGroupPricing(
                $groupItems->all(),
                $product,
            );
            $total += $pricing['total'] + $pricing['guest_surcharge'];

            foreach ($groupItems as $item) {
                $total += (float) $item->extra_fee;
            }
        }

        foreach ($items->filter(fn ($item) => ! $item->product_id) as $item) {
            $total += (float) $item->price + (float) $item->extra_fee;
        }

        $total += $order->relationLoaded('services')
            ? (float) $order->services->sum('subtotal')
            : (float) $order->services()->sum('subtotal');

        return (int) round($total);
    }

    // ─── Xử lý đơn deposit (cập nhật full_amount) ───────────────────────────

    /**
     * Với đơn deposit: GHI ĐÈ extra_charge_amount = $diff (không cộng dồn) và xóa link remaining
     * cũ để force regenerate khi khách thanh toán lần 2.
     *
     * $diff = calculateDiff($order) — đã là số RÒNG hiện tại (tổng giá thật − full_amount −
     * settled_adjustment_total), tức "đang phát sinh/cần hoàn bao nhiêu TÍNH ĐẾN BÂY GIỜ", không
     * phải riêng phần thay đổi của lần sửa này — nên phải SET, cộng thêm vào extra_charge_amount
     * cũ sẽ tính lặp lại phần đã nằm trong đó.
     */
    public function applyDiffToDeposit(Order $order, int $diff): void
    {
        $order->update([
            'extra_charge_amount'    => $diff !== 0 ? $diff : null,
            'remaining_payos_code'   => null,
            'remaining_checkout_url' => null,
            'remaining_qr_code'      => null,
        ]);

        Log::info('ExtraCharge: deposit extra_charge_amount updated', [
            'order_id'      => $order->id,
            'deposit_pct'   => $order->deposit_percent,
            'new_extra'     => $diff,
            'full_amount'   => $order->full_amount,
        ]);
    }

    // ─── Xử lý đơn đã paid (tạo PayOS link mới) ────────────────────────────

    /**
     * Với đơn đã paid: tạo PayOS link cho khoản phát sinh và lưu vào extra_charge_* fields.
     * Nếu đã có extra_charge_payos_code cũ chưa thanh toán → ghi đè.
     *
     * @return array{checkout_url: string, qr_code: string|null, amount: int}
     */
    public function createExtraChargePayOS(Order $order, int $amount): array
    {
        $payOS = new PayOS(
            Config::get('payos.client_id'),
            Config::get('payos.api_key'),
            Config::get('payos.checksum_key'),
        );

        $extraCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
        $expiredAt    = now()->addMinutes(15);
        $expiredAtTs  = $expiredAt->timestamp;

        $data = [
            'orderCode'   => $extraCode,
            'amount'      => $amount,
            'description' => 'PS them - ' . $order->order_code,
            'returnUrl'   => route('booking.detail', ['code' => $order->order_code]) . '?extra_paid=1',
            'cancelUrl'   => route('booking.detail', ['code' => $order->order_code]) . '?extra_cancelled=1',
            'buyerName'   => $order->buyer_name,
            'buyerPhone'  => $order->buyer_phone,
            'buyerEmail'  => $order->buyer_email ?? '',
            'expiredAt'   => $expiredAtTs,
            'items'       => [[
                'name'     => 'Phát sinh thêm - ' . $order->order_code,
                'quantity' => 1,
                'price'    => $amount,
            ]],
        ];

        $response    = $payOS->createPaymentLink($data);
        $checkoutUrl = $response['checkoutUrl'] ?? null;
        $qrCode      = $response['qrCode'] ?? null;

        if (! $checkoutUrl) {
            throw new \RuntimeException('PayOS không trả về checkout URL cho extra charge.');
        }

        $order->update([
            'extra_charge_amount'         => $amount,
            'extra_charge_payos_code'     => $extraCode,
            'extra_charge_checkout_url'   => $checkoutUrl,
            'extra_charge_qr_code'        => $qrCode,
            'extra_charge_payment_method' => 'payos',
            'extra_charge_paid_at'        => null,
            'extra_charge_expired_at'     => $expiredAt,
        ]);

        Log::info('ExtraCharge: PayOS link created', [
            'order_id'   => $order->id,
            'extra_code' => $extraCode,
            'amount'     => $amount,
        ]);

        return [
            'checkout_url' => $checkoutUrl,
            'qr_code'      => $qrCode,
            'amount'       => $amount,
            'expired_at'   => $expiredAt->toIso8601String(),
        ];
    }

    /**
     * Tạo (hoặc trả lại link đã có) PayOS link cho phần còn lại của đơn deposit,
     * bao gồm extra_charge_amount đã cộng dồn. Dùng chung cho remainingPayment()
     * và luồng khách tự đặt thêm trên đơn đang deposit.
     *
     * @return array{checkout_url: string, qr_code: string|null, amount: int, expired_at: string}|array{error: string}
     */
    public function createRemainingPayOS(Order $order): array
    {
        $depositPct  = (int) $order->deposit_percent;
        $depositPaid = (int) $order->full_amount;
        $realTotal   = $depositPct > 0 ? (int) round($depositPaid * 100 / $depositPct) : $depositPaid;
        $extraCharge = (int) ($order->extra_charge_amount ?? 0);
        $remaining   = ($realTotal - $depositPaid) + $extraCharge;

        if ($order->remaining_checkout_url && $order->remaining_payos_code) {
            return [
                'checkout_url' => $order->remaining_checkout_url,
                'qr_code'      => $order->remaining_qr_code,
                'amount'       => $remaining,
                'expired_at'   => null,
            ];
        }

        if ($remaining < 2000) {
            return ['error' => 'Số tiền còn lại quá nhỏ hoặc đã thanh toán đủ.'];
        }

        $payOS = new PayOS(
            Config::get('payos.client_id'),
            Config::get('payos.api_key'),
            Config::get('payos.checksum_key'),
        );

        $remainingCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
        $expiredAt     = now()->addMinutes(30);

        $response = $payOS->createPaymentLink([
            'orderCode'   => $remainingCode,
            'amount'      => $remaining,
            'description' => 'Tt con lai - ' . $order->order_code,
            'returnUrl'   => route('booking.detail', ['code' => $order->order_code]) . '?remaining_success=1',
            'cancelUrl'   => route('booking.detail', ['code' => $order->order_code]) . '?remaining_cancelled=1',
            'buyerName'   => $order->buyer_name ?? '',
            'buyerPhone'  => $order->buyer_phone ?? '',
            'expiredAt'   => $expiredAt->timestamp,
            'items'       => [[
                'name'     => 'Tiền còn lại - ' . ($order->items->first()?->name ?? 'Phòng'),
                'quantity' => 1,
                'price'    => $remaining,
            ]],
        ]);

        $checkoutUrl = $response['checkoutUrl'] ?? null;
        $qrCode      = $response['qrCode'] ?? null;

        if (! $checkoutUrl) {
            return ['error' => 'Không thể tạo link thanh toán.'];
        }

        $order->update([
            'remaining_payos_code'   => $remainingCode,
            'remaining_checkout_url' => $checkoutUrl,
            'remaining_qr_code'      => $qrCode,
        ]);

        return [
            'checkout_url' => $checkoutUrl,
            'qr_code'      => $qrCode,
            'amount'       => $remaining,
            'expired_at'   => $expiredAt->toIso8601String(),
        ];
    }

    /**
     * Đánh dấu đã thu tiền mặt cho extra charge (admin thu tay).
     */
    public function markExtraChargeAsCash(Order $order, int $amount): void
    {
        $order->update([
            'extra_charge_amount'         => $amount,
            'extra_charge_payos_code'     => null,
            'extra_charge_checkout_url'   => null,
            'extra_charge_qr_code'        => null,
            'extra_charge_payment_method' => 'cod',
            'extra_charge_paid_at'        => now(),
            'extra_charge_expired_at'     => null,
            // Ghi nhớ khoản NÀY đã được xử lý xong — để lần calculateDiff() kế tiếp không tính
            // lặp lại phần đã thu này (xem ghi chú ở calculateDiff()).
            'settled_adjustment_total'    => (int) ($order->settled_adjustment_total ?? 0) + $amount,
        ]);

        Log::info('ExtraCharge: cash collected', [
            'order_id' => $order->id,
            'amount'   => $amount,
        ]);
    }

    /**
     * Đánh dấu đã nhận chuyển khoản cho extra charge (admin xác nhận tay).
     */
    public function markExtraChargeAsTransfer(Order $order, int $amount): void
    {
        $order->update([
            'extra_charge_amount'         => $amount,
            'extra_charge_payos_code'     => null,
            'extra_charge_checkout_url'   => null,
            'extra_charge_qr_code'        => null,
            'extra_charge_payment_method' => 'bank_transfer',
            'extra_charge_paid_at'        => now(),
            'extra_charge_expired_at'     => null,
            'settled_adjustment_total'    => (int) ($order->settled_adjustment_total ?? 0) + $amount,
        ]);

        Log::info('ExtraCharge: bank transfer collected', [
            'order_id' => $order->id,
            'amount'   => $amount,
        ]);
    }

    /**
     * Xử lý khi PayOS webhook báo extra charge đã thanh toán.
     */
    public function handleExtraChargePaid(Order $order): void
    {
        $order->update([
            'extra_charge_payment_method' => 'payos',
            'extra_charge_paid_at'        => now(),
            'extra_charge_expired_at'     => null,
            'settled_adjustment_total'    => (int) ($order->settled_adjustment_total ?? 0) + (int) ($order->extra_charge_amount ?? 0),
        ]);

        Log::info('ExtraCharge: paid via PayOS', ['order_id' => $order->id]);
    }

    // ─── Xử lý đơn paid bị GIẢM giá (huỷ khung giờ/dịch vụ) ─────────────────

    /**
     * Ghi nhận khoản CẦN HOÀN cho khách khi đơn đã paid bị huỷ bớt khung giờ/dịch vụ làm giá
     * giảm. Không thể "hoàn qua QR" như chiều thu thêm (PayOS chỉ tạo link THU tiền) — admin phải
     * tự chuyển khoản/trả tiền mặt NGOÀI hệ thống rồi bấm xác nhận qua markRefundAsDone(). Field
     * này CHỈ để hiện panel "Hoàn tiền chưa xử lý" cho admin theo dõi — trước đây khoản này không
     * lưu ở đâu cả, chỉ có 1 Notification thoáng qua, admin bỏ lỡ là mất dấu hoàn toàn.
     */
    public function recordPendingRefund(Order $order, int $amount): void
    {
        $order->update([
            'extra_refund_amount'  => $amount,
            'extra_refund_method'  => null,
            'extra_refund_paid_at' => null,
        ]);

        Log::info('ExtraCharge: pending refund recorded', [
            'order_id' => $order->id,
            'amount'   => $amount,
        ]);
    }

    /**
     * Admin xác nhận ĐÃ hoàn tiền cho khách (tiền mặt/chuyển khoản, xử lý ngoài hệ thống).
     */
    public function markRefundAsDone(Order $order, int $amount, string $method = 'cash'): void
    {
        $order->update([
            'extra_refund_amount'  => $amount,
            'extra_refund_method'  => $method,
            'extra_refund_paid_at' => now(),
            // Hoàn tiền làm GIẢM tổng đã "chốt" — trừ đi (ngược chiều với thu thêm ở
            // markExtraChargeAsCash/Transfer/handleExtraChargePaid).
            'settled_adjustment_total' => (int) ($order->settled_adjustment_total ?? 0) - $amount,
        ]);

        Log::info('ExtraCharge: refund marked as done', [
            'order_id' => $order->id,
            'amount'   => $amount,
            'method'   => $method,
        ]);
    }
}
