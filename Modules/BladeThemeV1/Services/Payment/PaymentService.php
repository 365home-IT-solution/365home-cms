<?php

namespace Modules\BladeThemeV1\Services\Payment;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use PayOS\PayOS;
use Exception;

class PaymentService
{
    protected $payOS;
    protected $domain;
    protected $isConfigured = false;
    protected $configError = null;

    public function __construct()
    {
        $this->domain = "https://localhome.goldenbeeltd.top";
        $this->initializePayOS();
    }

    protected function initializePayOS()
    {
        $clientId = Config::get('payos.client_id');
        $apiKey = Config::get('payos.api_key');
        $checksumKey = Config::get('payos.checksum_key');

        // Kiểm tra xem các thông tin cấu hình có tồn tại không
        if (empty($clientId) || empty($apiKey) || empty($checksumKey)) {
            $this->isConfigured = false;
            $missing = [];

            if (empty($clientId)) $missing[] = 'Client ID';
            if (empty($apiKey)) $missing[] = 'API Key';
            if (empty($checksumKey)) $missing[] = 'Checksum Key';

            $this->configError = 'PayOS chưa được cấu hình đầy đủ. Thiếu: ' . implode(', ', $missing);

            Log::warning('PayOS Configuration Error: ' . $this->configError);
            return;
        }

        try {
            $this->payOS = new PayOS($clientId, $apiKey, $checksumKey);
            $this->isConfigured = true;
        } catch (Exception $e) {
            $this->isConfigured = false;
            $this->configError = 'Không thể khởi tạo PayOS: ' . $e->getMessage();
            Log::error('PayOS Initialization Error: ' . $e->getMessage());
        }
    }

    protected function checkConfiguration()
    {
        if (!$this->isConfigured) {
            throw new Exception($this->configError ?? 'PayOS chưa được cấu hình đúng. Vui lòng kiểm tra lại cấu hình trong file config.');
        }
    }

    public function createRoomBookingPaymentData($order, $roomDetails = [])
    {
        $this->checkConfiguration();

        $items = [];
        $totalAmount = 0;
        $expiredAt = now()->addMinutes(15)->timestamp;

        if ($order->items && $order->items->count() > 0) {
            foreach ($order->items as $item) {
                // Thêm item phòng với giá gốc
                $items[] = [
                    'name' => $item->name . ' (1 phòng)',
                    'quantity' => $item->quantity ?? 1,
                    'price' => (int) $item->price, // Giá phòng gốc không cộng extra_fee
                ];
                $totalAmount += (int) $item->price * ($item->quantity ?? 1);

                // Nếu có phụ phí, thêm như một item riêng
                if ($item->extra_fee > 0) {
                    $items[] = [
                        'name' => 'Phụ phí thêm khách',
                        'quantity' => 1,
                        'price' => (int) $item->extra_fee,
                    ];
                    $totalAmount += (int) $item->extra_fee;
                }
            }
        } else {
            // Fallback nếu không có items
            $items[] = [
                'name' => 'Đặt phòng - ' . ($roomDetails['productName'] ?? 'Phòng'),
                'quantity' => 1,
                'price' => (int) $order->amount,
            ];
            $totalAmount = (int) $order->amount;
        }

        Log::info('PayOS Payment Items', [
            'items' => $items,
            'total_amount' => $totalAmount,
            'order_amount' => $order->amount
        ]);

        // Đảm bảo totalAmount khớp với order->amount
        if ($totalAmount != $order->amount) {
            $totalAmount = (int) $order->amount; // Ưu tiên amount trong order
        }

        $data = [
            "orderCode" => (int) $order->order_code,
            "amount" => $totalAmount,
            "description" => mb_substr($order->description ?? 'Thanh toan dat phong', 0, 25),
            'returnUrl' => route('payment.success', ['orderCode' => $order->order_code]), // Sửa: dùng order_code
            'cancelUrl' => route('payment.cancel', ['orderCode' => $order->order_code]), // Sửa: dùng order_code
            "items" => $items,
            "buyerName" => $order->buyer_name,
            "buyerEmail" => $order->buyer_email ?? '',
            "buyerPhone" => $order->buyer_phone,
            "buyerAddress" => $order->buyer_address ?? '',
            "expiredAt" => $expiredAt,
        ];

        return $data;
    }

    public function createPayOSPaymentData($order, $cartItems)
    {
        $this->checkConfiguration();
        $expiredAt = now()->addMinutes(15)->timestamp;
        $description = $order->description;
        if (empty($description)) {
            $description = 'Thanh toán đặt phòng';
        }
        $data = [
            "orderCode" => $order->order_code,
            "amount" => $order->amount,
            "description" => $description,
            "returnUrl" => $this->domain . "/success.html",
            "cancelUrl" => $this->domain . "/cancel.html",
            "items" => $cartItems,
            "buyerName" => $order->buyer_name,
            "buyerEmail" => $order->buyer_email,
            "buyerPhone" => $order->buyer_phone,
            "buyerAddress" => $order->buyer_address,
            "expiredAt"    => $expiredAt,
        ];

        $data['signature'] = $this->generateSignature($data);

        return $data;
    }

    public function createPaymentLink($data)
    {
        $this->checkConfiguration();

        try {
            return $this->payOS->createPaymentLink($data);
        } catch (Exception $e) {
            Log::error('PayOS Create Payment Link Error: ' . $e->getMessage());
            throw new Exception('Không thể tạo link thanh toán: ' . $e->getMessage());
        }
    }

    protected function generateSignature($data)
    {
        $checksumKey = Config::get('payos.checksum_key');
        if (empty($checksumKey)) {
            throw new Exception('Checksum key không tồn tại');
        }
        return hash_hmac('sha256', json_encode($data), $checksumKey);
    }

    public function getPaymentLinkInformation($orderCode)
    {
        $this->checkConfiguration();

        try {
            return $this->payOS->getPaymentLinkInformation($orderCode);
        } catch (Exception $e) {
            Log::error('PayOS Get Payment Info Error: ' . $e->getMessage());
            throw new Exception('Không thể lấy thông tin thanh toán: ' . $e->getMessage());
        }
    }

    // Thêm method để kiểm tra trạng thái cấu hình từ bên ngoài
    public function isConfigured()
    {
        return $this->isConfigured;
    }

    public function getConfigError()
    {
        return $this->configError;
    }
}