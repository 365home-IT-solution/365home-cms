<?php

namespace Modules\BladeThemeV1\Services\Payment;

use Modules\Payment\Entities\Order;

class OrderHandlerService
{
    protected $addressService;
    protected $paymentService;

    public function __construct(AddressService $addressService, PaymentService $paymentService)
    {
        $this->addressService = $addressService;
        $this->paymentService = $paymentService;
    }

    public function prepareOrderData($data)
    {
        $address = $this->addressService->getFullAddress(
            $data['provinces'],
            $data['districts'],
            $data['wards'],
            $data['provinceId'],
            $data['districtId'],
            $data['wardId'],
            $data['buyerAddress']
        );

        return [
            'order_code' => $this->generateOrderCode(),
            'amount' => $data['finalTotal'],
            // 'items' => json_encode($data['cart']),
            'buyer_name' => $data['buyerName'],
            'buyer_email' => $data['buyerEmail'],
            'buyer_phone' => $data['buyerPhone'],
            'buyer_address' => $address['fullAddress'],
            'description' => $data['description'],
            'ward_name' => $address['wardName'],
            'district_name' => $address['districtName'],
            'province_name' => $address['provinceName'],
            'payment_method' => $data['paymentMethod'],
            'shipping_method' => $data['shippingMethod'],
            'status' => 'pending'
        ];
    }

    private function generateOrderCode()
    {
        return intval(substr(strval(microtime(true) * 10000), -6));
    }

    public function createOrder(array $orderData, array $cartItems)
    {
        $order = Order::create($orderData);

        // Thêm dữ liệu vào bảng order_items
        foreach ($cartItems as $item) {
            $order->items()->create([
                'product_id' => $item['id'] ?? null,
                'name' => $item['name'],
                'price' => $item['price'],
                'discount' => $item['discount'] ?? 0,
                'vat' => $item['vat'] ?? null,
                'quantity' => $item['quantity'],
                'is_shipped' => $item['is_shipped'] ?? true
            ]);
        }

        return $order;
    }
}