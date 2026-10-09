<?php

// Đối soát hoa hồng đối tác Homestay — các con số là giá trị ĐỀ XUẤT trong bản mô tả nghiệp vụ (06/10/2026),
// đổi qua .env khi chủ dự án chốt.
return [
    // Số ngày đối tác phải nộp hoa hồng kể từ khi bảng đối soát được gửi; quá hạn ⇒ tự trừ ký quỹ.
    'overdue_days' => (int) env('SETTLEMENT_OVERDUE_DAYS', 5),

    // Nhắc đối tác trước hạn nộp bao nhiêu ngày.
    'remind_before_days' => (int) env('SETTLEMENT_REMIND_BEFORE_DAYS', 1),

    // true = bảng đối soát vừa sinh ra được gửi đối tác luôn; false = để nháp cho Super Admin xem rồi bấm gửi.
    'auto_send' => (bool) env('SETTLEMENT_AUTO_SEND', false),

    // Link QR nộp hoa hồng (PayOS của 365home) có hạn bao nhiêu giờ.
    'payment_link_hours' => (int) env('SETTLEMENT_PAYMENT_LINK_HOURS', 24),

    // Đơn có nhận phòng → trả phòng cách nhau dưới số phút này mà có khoản 365home bù thì bị giữ khoản bù để xem xét.
    'suspicious_stay_minutes' => (int) env('SETTLEMENT_SUSPICIOUS_STAY_MINUTES', 30),

    // Chiết khấu hệ thống (đặt trọn ngày, đặt nhiều khung) do ai chịu: partner (đối tác bật — mặc định) | platform (365home áp toàn sàn, 365home bù đủ) |
    // shared (đồng chịu, % đối tác chịu bên dưới). Chụp vào từng đơn lúc đặt.
    'system_discount_funded_by'        => env('SETTLEMENT_SYSTEM_DISCOUNT_FUNDED_BY', 'partner'),
    'system_discount_partner_share_pct' => (int) env('SETTLEMENT_SYSTEM_DISCOUNT_PARTNER_SHARE_PCT', 50),

    // Đơn đã thanh toán, kỳ lưu trú kết thúc quá số giờ này mà chưa được đánh dấu trả phòng vẫn được coi là hoàn thành để chốt hoa hồng (khách không đến, không có khoá thông minh...).
    'auto_complete_hours' => (int) env('SETTLEMENT_AUTO_COMPLETE_HOURS', 12),

    // Đối tác có số giờ này để hoàn tiền cho khách kể từ khi có yêu cầu hoàn; quá hạn thì 365home hoàn thay rồi trừ ký quỹ (xem RefundClaimService).
    'refund_grace_hours' => (int) env('SETTLEMENT_REFUND_GRACE_HOURS', 24),

    // Hoá đơn GTGT cho phần hoa hồng: thuế suất (%) áp lên số hoa hồng của kỳ (số hoa hồng là giá CHƯA thuế).
    'vat_percent' => (int) env('SETTLEMENT_VAT_PERCENT', 10),

    // Phòng được tăng giá từ ngưỡng % này trong số ngày này trước ngày đặt mà đơn lại dùng voucher 365home ⇒ giữ khoản bù để đối chiếu giá niêm yết.
    'price_increase_percent'       => (int) env('SETTLEMENT_PRICE_INCREASE_PERCENT', 10),
    'price_increase_lookback_days' => (int) env('SETTLEMENT_PRICE_INCREASE_LOOKBACK_DAYS', 30),
];
