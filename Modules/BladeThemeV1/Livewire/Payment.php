<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Services\Payment\AddressService;
use Modules\BladeThemeV1\Services\Payment\OrderHandlerService;
use Modules\BladeThemeV1\Services\Payment\PaymentService;
use Modules\BladeThemeV1\Services\Payment\ShippingService;
use Modules\BladeThemeV1\Traits\AddressHandlerTrait;
use Modules\BladeThemeV1\Traits\CartTrait;
use Modules\BladeThemeV1\Traits\GHNServiceTrait;
use Modules\BladeThemeV1\Traits\ValidationRulesTrait;

//use Modules\ThemeTHS\Traits\GHTKServiceTrait;


class Payment extends Component
{
    use CartTrait, AddressHandlerTrait, GHNServiceTrait, ValidationRulesTrait;
    public $cart;
    public $buyerName, $buyerPhone, $buyerEmail, $description, $buyerAddress, $acceptTerms = false, $paymentMethod = 'cod'; //PayOS
    public $provinceId, $districtId, $wardId;
    public $provinces = [], $districts = [], $wards = [];
    public $shippingMethod = 'self'; //GHN, GHTK
    public $discount = 0, $items = [];
    public $shippingFee;
    public $pickAddressId, $pickAddress, $pickTel, $pickName, $pickProvince, $pickDistrict;
    protected $listeners = ['cartUpdated' => 'loadCart'];
    protected $addressService;
    protected $paymentService;
    protected $orderHandler;
    protected $shippingService;

    /*/
     * CartTrait : Xử lý các thao tác liên quan đến giỏ hàng
     * AddressHandlerTrait : Xử lý các thao tác liên quan đến địa chỉ
    /*/

    public function boot(
        AddressService $addressService, // Xử lý địa chỉ (đã có)
        PaymentService $paymentService, // Xử lý thanh toán (đã có)
        OrderHandlerService $orderHandler, // Xử lý tạo và quản lý đơn hàng
        ShippingService $shippingService // Xử lý tính toán phí vận chuyển
    ) {
        $this->addressService = $addressService;
        $this->paymentService = $paymentService;
        $this->orderHandler = $orderHandler;
        $this->shippingService = $shippingService;
    }

    public function mount()
    {
        $this->loadCart();
        $this->fetchProvinces();

        if ($this->provinceId) {
            $this->fetchDistricts();
        }
        if ($this->districtId) {
            $this->fetchWards($this->districtId);
        }
        $this->getShippingFee();
    }

    public function changeShippingMethod($method)
    {
        $this->shippingMethod = $method;
        $this->getShippingFee();
    }

    public function getFinalTotal() // Tổng giá cuối cùng
    {
        $totalAmount = $this->getTotalAmount();
        return max(0, $totalAmount + $this->shippingFee - $this->discount);
    }

    public function getShippingFee() // Cập nhật phí vận chuyển ngay sau khi thay đổi phương thức
    {
        if (!$this->provinceId || !$this->districtId) {
            return;
        }

        if ($this->shippingMethod === 'GHN' && $this->wardId) {
            $params = $this->shippingService->prepareGHNParams([
                'districtId' => $this->districtId,
                'wardId' => $this->wardId
            ]);
            $this->shippingFee = $this->shippingService->calculateShippingFee('GHN', $params);
        } elseif ($this->shippingMethod === 'GHTK') {
            $params = $this->shippingService->prepareGHTKParams([
                'pickAddressId' => $this->pickAddressId,
                'pickAddress' => $this->pickAddress,
                'pickProvince' => $this->pickProvince,
                'pickDistrict' => $this->pickDistrict,
                'provinceName' => $this->getProvinceName(),
                'districtName' => $this->getDistrictName(),
                'wardName' => $this->getWardName()
            ]);
            $this->shippingFee = $this->shippingService->calculateShippingFee('GHTK', $params);
        } else {
            $this->shippingFee = null;
        }
    }

    public function createPaymentLink()
    {
        $this->validateCheckout();

        $orderData = $this->orderHandler->prepareOrderData([
            'provinces' => $this->provinces,
            'districts' => $this->districts,
            'wards' => $this->wards,
            'provinceId' => $this->provinceId,
            'districtId' => $this->districtId,
            'wardId' => $this->wardId,
            'buyerAddress' => $this->buyerAddress,
            'finalTotal' => $this->getFinalTotal(),
            'buyerName' => $this->buyerName,
            'buyerEmail' => $this->buyerEmail,
            'buyerPhone' => $this->buyerPhone,
            'description' => $this->description,
            'paymentMethod' => $this->paymentMethod,
            'shippingMethod' => $this->shippingMethod
        ]);

        // Chuyển `$this->cart` làm tham số cho `createOrder`
        $order = $this->orderHandler->createOrder($orderData, $this->cart);
        return $this->processPayment($order);
    }

    protected function processPayment($order)
    {
        return $this->paymentMethod === 'PayOS'
            ? $this->processPayOSPayment($order)
            : $this->processCODPayment();
    }

    protected function processPayOSPayment($order)
    {
        try {
            $paymentData = $this->paymentService->createPayOSPaymentData($order, $this->cart);
            $paymentLink = $this->paymentService->createPaymentLink($paymentData);

            $order->update([
                'payment_url' => $paymentLink['checkoutUrl'],
                'status' => 'pending'
            ]);

            $this->resetInputFields();
            return redirect()->to($paymentLink['checkoutUrl']);
        } catch (\Throwable $th) {
            $order->update(['status' => 'failed']);
            session()->flash('error', 'Đã xảy ra lỗi: ' . $th->getMessage());
            $this->dispatch('notify', [
                'message' => 'Thanh toán thất bại. Vui lòng thử lại!',
                'type' => 'error'
            ]);
        }
    }

    protected function processCODPayment()
    {
        $this->resetInputFields();
        $this->dispatch('notify', [
            'message' => 'Đơn hàng đã được tạo thành công với phương thức COD!',
            'type' => 'success'
        ]);
    }

    private function resetInputFields()
    {
        $this->reset([
            'buyerName',
            'buyerPhone',
            'buyerEmail',
            'description',
            'buyerAddress',
            'provinceId',
            'districtId',
            'wardId',
            'shippingMethod',
            'paymentMethod',
            'acceptTerms',
            'discount',
            'shippingFee'
        ]);
        $this->items = $this->clearCart();
    }


    public function render()
    {
        return view('bladethemev1::livewire.payment', [
            'cart' => $this->cart,
            'shippingFee' => $this->shippingFee,
            'discount' => $this->discount,
            'finalTotal' => $this->getFinalTotal(),
        ]);
    }
}