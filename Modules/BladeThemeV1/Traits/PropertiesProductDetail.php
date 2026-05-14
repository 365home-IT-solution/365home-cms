<?php

namespace Modules\BladeThemeV1\Traits;

trait PropertiesProductDetail
{
    /** Public Properties **/
    public $overNight = 0;
    public $slug;
    public $product;
    public $mediaSecond;
    public $shortDescription;
    public $categories;
    public $timeSlots;
    public $dates;
    public $primaryColor;
    public $productTags = [];
    public $roomTimeSlots = [];
    public $buyerName = '';
    public $buyerPhone = '';
    public $guests = 2;
    public $startTime = '';
    public $endTime = '';
    public $cccd_front = '';
    public $cccd_back = '';
    public $note = '';
    public $totalAmount = 0;
    public $accept1 = false;
    public $accept2 = false;
    public $acceptRefundPolicy = false;
    public $selectedSlot = null;
    public $extraFee = 0;
    public bool $showModal = false;
    public $discountAmount = 0;
    public $isCalculating = false;
    public $paymentMethod = 'PayOS';
    public $selectedSlots = [];
    public $style2CheckinTime = '14:00';
    public $style2CheckoutTime = '12:00';
    // 'deposit' = cọc theo %, 'full' = thanh toán 100%
    public string $paymentOption = 'deposit';

    
    /** 
     * Kiểu hiển thị form đặt phòng:
     * 1 = Bảng slot theo giờ (calendar table, trạng thái, tổng tiền tạm tính)
     * 2 = Chọn ngày bắt đầu / kết thúc (daterange picker)
     * Được set tự động từ $product->styles trong initializeProductData()
    **/
    public int $bookingStyle = 1;

    /** Protected Properties **/
    protected $paymentService;
    protected $orderHandler;
    protected $bookedDates = [];
}