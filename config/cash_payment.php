<?php

// Thanh toán tiền mặt tại quầy (payment_method = cod). Giá trị nhập ở CMS (Cấu hình web → Tiền mặt tại quầy) được ưu tiên; để trống thì theo .env.
return [
    // Đơn chưa thu tiền, khách chưa nhận phòng: quá giờ nhận phòng số giờ này thì tự huỷ (khách không đến).
    'no_show_hours' => (int) env('CASH_NO_SHOW_HOURS', 3),

    // Chống huỷ đơn để "đi cửa sau": đếm đơn tiền mặt bị huỷ trong NGÀY / THÁNG (theo lịch, giờ Việt Nam).
    'guard_enabled' => (bool) env('CASH_CANCEL_GUARD_ENABLED', true),
    // Đối tác: chỉ đếm đơn do CHÍNH đối tác huỷ. Đạt ngưỡng → tạm khoá mọi phòng đang đăng, đối tác không tự mở lại được cho tới khi Super Admin mở khoá.
    'partner_day_limit' => (int) env('CASH_CANCEL_PARTNER_DAY_LIMIT', 3),
    'partner_month_limit' => (int) env('CASH_CANCEL_PARTNER_MONTH_LIMIT', 15),
    // Khách (cùng số điện thoại): đếm mọi đơn tiền mặt bị huỷ của khách. Đạt ngưỡng → chỉ thông báo Super Admin + đối tác, không khoá.
    'customer_day_limit' => (int) env('CASH_CANCEL_CUSTOMER_DAY_LIMIT', 3),
    'customer_month_limit' => (int) env('CASH_CANCEL_CUSTOMER_MONTH_LIMIT', 15),
];
