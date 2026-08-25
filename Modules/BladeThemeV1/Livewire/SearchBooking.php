<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Payment\Entities\Order;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;

class SearchBooking extends Component
{
    public $sdt = '';
    public $orders = [];
    public $remainingError = '';
    public $depositError = '';

    protected $rules = [
        'sdt' => 'required|numeric|digits_between:10,12',
    ];

    protected $messages = [
        'sdt.required' => 'Vui lòng nhập số điện thoại.',
        'sdt.numeric' => 'Số điện thoại phải là dạng số.',
        'sdt.digits_between' => 'Số điện thoại không hợp lệ (10-12 số).',
    ];

    public function getBooking()
    {
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->orders = [];
            throw $e;
        }

        $phone = preg_replace('/[^0-9]/', '', $this->sdt);

        if (!empty($phone)) {
            $this->orders = Order::with([
                'items.product.manualLockPasswords',
                'accessCodes',
                'category',
                ])
                ->where('buyer_phone', $phone)
                ->where(function ($q) {
                    // Đơn đã thanh toán hoặc đã cọc
                    $q->whereIn('status', ['paid', 'deposit']);
                })
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();
        } else {
            $this->orders = [];
        }

        $this->remainingError = '';
    }

    public function createDepositPayment(string $orderCode)
    {
        $this->depositError = '';

        $phone = preg_replace('/[^0-9]/', '', $this->sdt);

        if (empty($phone)) {
            $this->depositError = 'Vui lòng tra cứu đơn bằng số điện thoại trước khi thanh toán.';
            return;
        }

        try {
            $order = Order::with('items')
                ->where('order_code', $orderCode)
                ->where('buyer_phone', $phone)
                ->firstOrFail();

            if ($order->status !== 'deposit' || $order->deposit_percent === null) {
                $this->depositError = 'Đơn này không ở trạng thái chờ thanh toán cọc.';
                return;
            }

            // 'amount' lưu TỔNG GIÁ CỐ ĐỊNH của đơn (giống 'full_amount'), KHÔNG PHẢI tiền cọc cần
            // thu — xem Order::depositDueAmount()/ProductDetail::confirmBooking(). Dùng thẳng
            // $order->amount ở đây trước đó khiến khách quay lại thanh toán cọc bị tính NHẦM thành
            // toàn bộ giá trị đơn thay vì đúng % cọc.
            $depositAmount = $order->depositDueAmount();

            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            $payOS = new PayOS($clientId, $apiKey, $checksumKey);

            $retryCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));

            $response = $payOS->createPaymentLink([
                'orderCode'   => $retryCode,
                'amount'      => $depositAmount,
                'description' => 'Coc phong - ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?order_code=' . $order->order_code,
                'cancelUrl'   => route('payment.success') . '?order_code=' . $order->order_code . '&cancelled=1',
                'buyerName'   => $order->buyer_name,
                'buyerPhone'  => $order->buyer_phone,
                'buyerEmail'  => $order->buyer_email ?? '',
                'expiredAt'   => now()->addMinutes(30)->timestamp,
                'items'       => [[
                    'name'     => 'Tiền cọc - ' . ($order->items->first()->name ?? 'Phòng'),
                    'quantity' => 1,
                    'price'    => $depositAmount,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if (!$checkoutUrl) {
                $this->depositError = 'Không thể tạo link thanh toán. Vui lòng thử lại.';
                return;
            }

            $order->update(['deposit_retry_payos_code' => $retryCode]);

            Log::info('SearchBooking: deposit retry link created', [
                'order_id'   => $order->id,
                'retry_code' => $retryCode,
                'amount'     => $depositAmount,
            ]);

            return $this->redirect($checkoutUrl, navigate: false);

        } catch (\Exception $e) {
            Log::error('SearchBooking createDepositPayment error', [
                'orderCode' => $orderCode,
                'error'     => $e->getMessage(),
            ]);
            $this->depositError = 'Lỗi: ' . $e->getMessage();
        }
    }

    public function createRemainingPayment(string $orderCode)
    {
        $this->remainingError = '';

        $phone = preg_replace('/[^0-9]/', '', $this->sdt);

        if (empty($phone)) {
            $this->remainingError = 'Vui lòng tra cứu đơn bằng số điện thoại trước khi thanh toán.';
            return;
        }

        try {
            $order = Order::with('items')
                ->where('order_code', $orderCode)
                ->where('buyer_phone', $phone)
                ->firstOrFail();

            if ($order->status !== 'deposit') {
                $this->remainingError = 'Đơn này không ở trạng thái chờ thanh toán còn lại.';
                return;
            }

            // Nếu đã tạo link trước đó → dùng lại luôn
            // Luôn tạo link mới để đảm bảo returnUrl đúng (không reuse link cũ)
            $order->update(['remaining_checkout_url' => null]);

            // Cùng lỗi với createDepositPayment() ở trên: 'amount' KHÔNG PHẢI tiền đã cọc, nên
            // fullAmount - amount luôn ra ~0 (2 cột lưu cùng 1 giá trị tổng giá) — khách không bao
            // giờ tạo được QR phần còn lại qua trang tra cứu này. Tính đúng qua
            // depositPaidAmount() (số tiền cọc THỰC TẾ, mặc định = depositDueAmount() trừ khi admin
            // tự sửa tay) + phát sinh thêm nếu có — cùng công thức OrderPaymentController::calcRemaining().
            $fullAmount  = (int) $order->full_amount;
            $depositPaid = $order->depositPaidAmount();
            $extraCharge = (int) ($order->extra_charge_amount ?? 0);
            $remaining   = max(0, ($fullAmount - $depositPaid) + $extraCharge);

            if ($remaining <= 0) {
                $this->remainingError = 'Không còn khoản cần thanh toán.';
                return;
            }

            $clientId    = Config::get('payos.client_id');
            $apiKey      = Config::get('payos.api_key');
            $checksumKey = Config::get('payos.checksum_key');

            $payOS = new PayOS($clientId, $apiKey, $checksumKey);

            $remainingCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));

            $response = $payOS->createPaymentLink([
                'orderCode'   => $remainingCode,
                'amount'      => $remaining,
                'description' => 'Tt con lai - ' . $order->order_code,
                'returnUrl'   => route('payment.success') . '?orderCode=' . $remainingCode,
                'cancelUrl'   => route('payment.success') . '?orderCode=' . $remainingCode . '&cancelled=1',
                'buyerName'   => $order->buyer_name,
                'buyerPhone'  => $order->buyer_phone,
                'buyerEmail'  => $order->buyer_email ?? '',
                'expiredAt'   => now()->addMinutes(30)->timestamp,
                'items'       => [[
                    'name'     => 'Tiền còn lại - ' . ($order->items->first()->name ?? 'Phòng'),
                    'quantity' => 1,
                    'price'    => $remaining,
                ]],
            ]);

            $checkoutUrl = $response['checkoutUrl'] ?? null;

            if (!$checkoutUrl) {
                $this->remainingError = 'Không thể tạo link thanh toán. Vui lòng thử lại.';
                return;
            }

            $order->update([
                'remaining_payos_code'   => $remainingCode,
                'remaining_checkout_url' => $checkoutUrl,
            ]);

            Log::info('SearchBooking: remaining payment link created', [
                'order_id'       => $order->id,
                'remaining_code' => $remainingCode,
                'amount'         => $remaining,
            ]);

            return $this->redirect($checkoutUrl, navigate: false);

        } catch (\Exception $e) {
            Log::error('SearchBooking createRemainingPayment error', [
                'orderCode' => $orderCode,
                'error'     => $e->getMessage(),
            ]);
            $this->remainingError = 'Lỗi: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return view('bladethemev1::livewire.search-booking', [
            'orders' => $this->orders
        ]);
    }
}