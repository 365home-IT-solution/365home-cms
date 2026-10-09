<?php

// Ký quỹ đối tác Homestay — các con số là giá trị ĐỀ XUẤT trong bản mô tả nghiệp vụ (06/10/2026), đổi qua .env khi chủ dự án chốt.
return [
    // Mức ký quỹ GỢI Ý cho 1 đối tác = max(tối thiểu, mỗi phòng × số phòng đang bán). Mức ÁP DỤNG là số Super Admin đặt cho đối tác.
    'suggested_per_room' => (int) env('ESCROW_SUGGESTED_PER_ROOM', 1_000_000),
    'suggested_minimum'  => (int) env('ESCROW_SUGGESTED_MINIMUM', 5_000_000),

    // Đối tác đang hoạt động: số ngày được nạp cho đủ kể từ khi Super Admin đặt mức ký quỹ (trong hạn này chưa khoá bán).
    'initial_grace_days' => (int) env('ESCROW_INITIAL_GRACE_DAYS', 30),

    // Số dư < 100% mức tối thiểu: số ngày được nạp bù trước khi tạm ngưng bán.
    'topup_days' => (int) env('ESCROW_TOPUP_DAYS', 7),

    // Số dư dưới ngưỡng này (% mức tối thiểu) thì tạm ngưng bán ngay, không chờ hạn nạp bù.
    'suspend_below_percent' => (int) env('ESCROW_SUSPEND_BELOW_PERCENT', 50),

    // Số ngày đối tác được đồng ý/khiếu nại một đề xuất trừ; hết hạn không phản hồi = đồng ý.
    'deduction_response_days' => (int) env('ESCROW_DEDUCTION_RESPONSE_DAYS', 3),

    // Chấm dứt hợp đồng: số ngày giữ ký quỹ để nhận khiếu nại trước khi được hoàn.
    'release_hold_days' => (int) env('ESCROW_RELEASE_HOLD_DAYS', 30),

    // Nạp qua QR PayOS của 365home.
    'deposit_min_amount' => (int) env('ESCROW_DEPOSIT_MIN_AMOUNT', 100_000),
    'deposit_link_hours' => (int) env('ESCROW_DEPOSIT_LINK_HOURS', 24),
];
