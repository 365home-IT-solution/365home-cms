<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Customer;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Payment\Entities\Order;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use App\Services\Payment\PayOsAccountResolver;
use PayOS\PayOS;

/**
 * Trang khách "Tra cứu đơn đặt phòng" (/ticket-booking): BẮT BUỘC đăng nhập. Chỉ hiện đơn của chính khách đang đăng nhập (đơn đặt lúc đã đăng nhập
 * hoặc đơn đặt bằng đúng SĐT của khách), không còn tra cứu theo SĐT tuỳ ý. Đơn admin đã ẩn khỏi tra cứu (hidden_from_lookup) và đơn khách đã tự
 * "xoá" ở trang tài khoản (customer_hidden_at) không hiện ở đây.
 */
class SearchBooking extends Component
{
    // Chỉ server gán sau khi xác thực token (#[Locked]: client không sửa được) — dùng để lọc đơn của khách.
    #[Locked]
    public bool $isLoggedIn = false;
    public bool $isLoading  = true;
    #[Locked]
    public ?string $customerId = null;
    #[Locked]
    public string $phone = '';
    #[Locked]
    public string $phone84 = '';

    public $orders = [];
    public $remainingError = '';
    public $depositError = '';

    // Xác thực token khách (cùng cách với trang tài khoản) rồi nạp đơn của khách.
    public function loadMyOrders(string $token): void
    {
        $this->isLoading = true;
        $customer = $this->customerFromToken($token);

        if (! $customer) {
            $this->isLoggedIn = false;
            $this->customerId = null;
            $this->phone = $this->phone84 = '';
            $this->orders = [];
            $this->isLoading = false;

            // Có token nhưng không hợp lệ (hết hạn, tài khoản bị vô hiệu...) → ép đăng xuất ở trình duyệt
            if ($token !== '') {
                $this->dispatch('auth-force-logout');
            }

            return;
        }

        $raw = $customer->phone ?? '';
        if (str_starts_with($raw, '84') && strlen($raw) >= 11) {
            $raw = '0' . substr($raw, 2);
        }

        $this->isLoggedIn = true;
        $this->customerId = (string) $customer->id;
        $this->phone      = $raw;
        $this->phone84    = '84' . substr($raw, 1);

        $this->orders = $this->ownedOrders()
            ->with([
                'items.product.manualLockPasswords',
                'accessCodes',
                'category',
            ])
            // Đơn đã thanh toán hoặc đã cọc
            ->whereIn('status', ['paid', 'deposit'])
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get();

        $this->remainingError = '';
        $this->depositError   = '';
        $this->isLoading      = false;
    }

    // Đơn của khách đang đăng nhập (theo customer_id hoặc SĐT đã xác thực OTP), trừ đơn bị ẩn.
    private function ownedOrders()
    {
        $phone      = $this->phone;
        $phone84    = $this->phone84;
        $customerId = $this->customerId;

        return Order::where(function ($q) use ($phone, $phone84, $customerId) {
            $q->where('customer_id', $customerId);
            if ($phone !== '') {
                $q->orWhere('buyer_phone', $phone)->orWhere('buyer_phone', $phone84);
            }
        })
            // Đơn admin đã "Ẩn khỏi tra cứu" không hiện ở trang này (đơn vẫn còn trong hệ thống).
            ->where('hidden_from_lookup', false)
            // Đơn khách đã tự "xoá" ở trang tài khoản (chỉ ẩn phía khách).
            ->whereNull('customer_hidden_at');
    }

    private function customerFromToken(string $token): ?Customer
    {
        if ($token === '') {
            return null;
        }

        try {
            $pat = PersonalAccessToken::findToken($token);
        } catch (\Throwable) {
            return null;
        }

        $customer = $pat && $pat->tokenable_type === Customer::class ? $pat->tokenable : null;

        return $customer && $customer->status === Customer::STATUS_ACTIVE ? $customer : null;
    }

    public function createDepositPayment(string $orderCode)
    {
        $this->depositError = '';

        if (! $this->isLoggedIn) {
            $this->depositError = 'Vui lòng đăng nhập để thanh toán.';
            return;
        }

        try {
            $order = $this->ownedOrders()->with('items')
                ->where('order_code', $orderCode)
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

            $payOS = PayOsAccountResolver::forOrderOrFail($order);

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

        if (! $this->isLoggedIn) {
            $this->remainingError = 'Vui lòng đăng nhập để thanh toán.';
            return;
        }

        try {
            $order = $this->ownedOrders()->with('items')
                ->where('order_code', $orderCode)
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

            $payOS = PayOsAccountResolver::forOrderOrFail($order);

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