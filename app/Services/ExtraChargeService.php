<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use PayOS\PayOS;

class ExtraChargeService
{
    // ─── Tính chênh lệch giá ────────────────────────────────────────────────

    /**
     * Tính chênh lệch giá sau khi admin cập nhật guest_count / services.
     *
     * @param  Order  $order            Đơn đã refresh với relations (items.product, services)
     * @param  int    $oldGuestCount
     * @param  int    $oldServicesTotal Tổng subtotal services CŨ (trước khi save)
     * @return int    Dương = khách còn nợ thêm, âm = giảm tiền
     */
    public function calculateDiff(Order $order, int $oldGuestCount, int $oldServicesTotal): int
    {
        $newServicesTotal = (int) $order->services->sum('subtotal');

        $product   = $order->items->where('extra_fee', 0)->first()?->product
                     ?? $order->items->first()?->product;
        $itemCount = $order->items->where('extra_fee', 0)->count() ?: $order->items->count();

        $oldSurcharge = $this->calcGuestSurcharge($product, $oldGuestCount, $itemCount);
        $newSurcharge = $this->calcGuestSurcharge($product, (int) $order->guest_count, $itemCount);

        return ($newServicesTotal - $oldServicesTotal) + ($newSurcharge - $oldSurcharge);
    }

    private function calcGuestSurcharge(?Product $product, int $guestCount, int $itemCount): int
    {
        if (! $product) {
            return 0;
        }

        $config         = $product->room_config ?? [];
        $guestFee       = (int) ($config['extra_guest_fee'] ?? 0);
        $guestThreshold = (int) ($config['max_free_guests'] ?? 2);
        $isSlotType     = (int) $product->styles === 1;
        $nights         = $isSlotType ? 1 : max(1, $itemCount);
        $extraGuests    = max(0, $guestCount - $guestThreshold);

        return $extraGuests * $guestFee * $nights;
    }

    // ─── Xử lý đơn deposit (cập nhật full_amount) ───────────────────────────

    /**
     * Với đơn deposit: cộng diff vào full_amount và xóa link remaining cũ
     * để force regenerate khi khách thanh toán lần 2.
     */
    public function applyDiffToDeposit(Order $order, int $diff): void
    {
        // full_amount = deposit đã thu (KHÔNG thay đổi).
        // Extra charge được cộng dồn vào extra_charge_amount.
        // remaining_payment API sẽ cộng extra_charge_amount vào remaining khi tính link QR.
        $currentExtra = (int) ($order->extra_charge_amount ?? 0);
        $newExtra     = $currentExtra + $diff;

        $order->update([
            'extra_charge_amount'    => $newExtra !== 0 ? $newExtra : null,
            'remaining_payos_code'   => null,
            'remaining_checkout_url' => null,
            'remaining_qr_code'      => null,
        ]);

        Log::info('ExtraCharge: deposit extra_charge_amount updated', [
            'order_id'      => $order->id,
            'deposit_pct'   => $order->deposit_percent,
            'diff'          => $diff,
            'old_extra'     => $currentExtra,
            'new_extra'     => $newExtra,
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
        ]);

        Log::info('ExtraCharge: cash collected', [
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
        ]);

        Log::info('ExtraCharge: paid via PayOS', ['order_id' => $order->id]);
    }
}
