<?php

namespace Modules\BladeThemeV1\Traits;

use Livewire\Attributes\Locked;

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
    public $buyerEmail = '';
    public $guests = 2;
    // Đồng bộ với ProductDetail::hasOvernightSlotSelected() — Alpine không đọc lại được method
    // PHP do khối chọn khách có wire:ignore.
    public bool $isOvernightBooking = false;
    public $startTime = '';
    public $endTime = '';
    // 1 ảnh CCCD mặt có mã QR (lưu vào cột cccd_qr_image) — quét ngay khi upload, xem HandlesCccdQrScan.
    public $cccd_qr_image = '';
    // CCCD người đi cùng — mảng, mỗi phần tử ứng với 1 khách từ khách thứ 2 trở đi (index 0 =
    // khách #2, index 1 = khách #3...). Chỉ hiển thị/bắt buộc khi có khung giờ qua đêm được chọn
    // (xem ProductDetail::hasOvernightSlotSelected()), số lượng = $guests - 1.
    public array $cccdQrImageExtra = [];
    // Trạng thái quét QR theo slot ('main', 'extra.0'...): ['ok' => bool, 'error' => ?string].
    // KHÔNG chứa thông tin cá nhân — dữ liệu CCCD nằm (mã hoá) trong session phía server và chỉ
    // được ghi vào đơn lúc tạo đơn.
    #[Locked]
    public array $cccdScanStatus = [];
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

    // Auth-prefill: set server-side via prefillFromAuth(), never trust client-set values —
    // #[Locked] để client không sửa được qua $set (gắn tài khoản/CCCD của người khác vào đơn).
    #[Locked]
    public bool    $isAuthUser    = false;
    #[Locked]
    public ?string $authUserId    = null; // UUID of authenticated customer
    // Hồ sơ có cccd_data hợp lệ → dùng lại, khách không phải tải CCCD.
    #[Locked]
    public bool    $authHasCccd   = false;

    // Lỗi phát sinh trong confirmBooking() (CCCD không hợp lệ, chưa đủ tuổi, v.v.) — hiển thị
    // ngay trong modal xác nhận đặt phòng thay vì toast 'notify' (bị che khuất sau modal do
    // z-index thấp hơn).
    public string $bookingConfirmError = '';

    
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