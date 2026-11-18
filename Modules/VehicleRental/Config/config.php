<?php

return [
    'name' => 'VehicleRental',

    // Khoảng đệm giữa 2 chuyến liền nhau trên cùng 1 xe (dọn xe, kiểm tra) — phút.
    'buffer_minutes' => 60,

    // Đơn chưa thanh toán giữ xe tối đa bao lâu — phút.
    'hold_minutes' => 15,

    // Đặt xe: phải đặt trước giờ nhận ít nhất bao nhiêu phút, tối đa bao nhiêu ngày thuê và đặt trước bao nhiêu ngày.
    'min_lead_minutes' => 30,
    'max_rental_days'  => 30,
    'max_advance_days' => 180,

    // Chính sách huỷ (áp cho đơn ĐÃ thanh toán): huỷ trước giờ nhận >= `hours` giờ thì hoàn `percent`% tiền thuê.
    // Sắp xếp giảm dần theo hours; không rơi vào mốc nào = không hoàn.
    'cancellation_tiers' => [
        ['hours' => 24, 'percent' => 100],
        ['hours' => 2, 'percent' => 50],
    ],

    // Thuê xe kèm đơn phòng: khung thuê xe phải nằm trong thời gian lưu trú, cho lệch tối đa bấy nhiêu giờ mỗi đầu
    // (VD nhận xe sớm trước giờ check-in hoặc trả xe sau giờ check-out).
    'room_link_tolerance_hours' => 6,

    // Thông báo đẩy (FCM) cho khách + chuông/FCM cho nhân viên. Tắt bằng VEHICLE_RENTAL_NOTIFICATIONS=false (phpunit.xml tắt sẵn
    // để test không gửi push thật tới khách có trong DB).
    'notifications' => [
        'enabled'                 => env('VEHICLE_RENTAL_NOTIFICATIONS', true),
        'pickup_reminder_minutes' => 120, // nhắc nhận xe trước giờ hẹn
        'return_reminder_minutes' => 60,  // nhắc trả xe trước hạn
        'overdue_after_minutes'   => 15,  // quá hạn trả bao lâu thì báo khách + nhân viên
    ],

    // GPS (module Motrak): đối chiếu km và cảnh báo cho xe đã ghép thiết bị GPS. Ngưỡng là ngưỡng NỘI BỘ để cảnh báo cho nhân viên, không phải quy định pháp luật.
    'gps' => [
        // Mặc định TẮT: GPS chỉ để xem vị trí trên bản đồ, không tạo cảnh báo và không gửi thông báo nào.
        'alerts_enabled' => env('VEHICLE_RENTAL_GPS_ALERTS', false),

        // Khu vực hành chính cho phép xe thuê chạy (khớp với "admin" Motrak trả về, không phân biệt hoa thường/dấu/tiền tố Tỉnh, TP).
        // Rỗng = TẮT cảnh báo "ra khỏi khu vực". VD: VEHICLE_RENTAL_GPS_ALLOWED_AREAS="Can Tho,Hau Giang"
        'allowed_areas' => array_values(array_filter(array_map('trim', explode(',', (string) env('VEHICLE_RENTAL_GPS_ALLOWED_AREAS', ''))))),

        'max_speed_kmh'               => ['car' => 100, 'motorbike' => 80],
        'unauthorized_min_speed_kmh'  => 10,   // xe không có đơn mà chạy từ tốc độ này...
        'unauthorized_min_distance_m' => 300,  // ...và đã rời chi nhánh từ khoảng cách này → nghi dùng trái phép
        'offline_after_minutes'       => 30,   // xe đang thuê mà mất tín hiệu GPS quá lâu (nghi tháo/ngắt thiết bị)
        'far_from_branch_m'           => 500,  // trả xe cách chi nhánh quá khoảng này → cảnh báo
        'pickup_branch_warning_m'     => 500,  // lúc giao xe, xe không ở gần chi nhánh → nhắc nhân viên
        'km_discrepancy_pct'          => 10,   // km GPS lệch odometer quá % này (và quá số km tối thiểu bên dưới) → cảnh báo
        'km_discrepancy_min_km'       => 10,
        // Chống báo lặp: cùng xe + cùng loại cảnh báo trong khoảng này (phút) chỉ báo 1 lần.
        'cooldown_minutes'            => ['out_of_area' => 60, 'speeding' => 30, 'unauthorized_use' => 60, 'offline_during_rental' => 120, 'return_far_from_branch' => 0, 'km_discrepancy' => 0],
        'track_margin_minutes'        => 5,    // lấy thêm các điểm GPS trước/sau chuyến thuê khi dựng hành trình
        'return_hold_minutes'         => 30,   // sau khi trả xe, vẫn lưu lịch sử GPS đầy đủ thêm khoảng này
    ],

    // Giao xe: sớm nhất bao nhiêu phút trước giờ nhận; được đánh dấu "không đến nhận" sau giờ nhận bao nhiêu phút.
    'pickup_early_minutes'  => 60,
    'no_show_after_minutes' => 120,

    // Hạng GPLX được phép thuê theo loại xe. CẦN luật sư/Sở GTVT xác nhận lại theo quy định hiện hành rồi chỉnh ở đây.
    'license_classes' => [
        'motorbike' => ['A1', 'A2', 'A3', 'A'],
        'car'       => ['B1', 'B2', 'B', 'C1', 'C', 'D1', 'D2', 'D', 'E'],
    ],

    // Vai trò nhân viên quầy thuê xe (xem đơn, duyệt giấy tờ, giao/nhận/tất toán) — tạo bởi migration create_rental_handover_tables.
    'staff_role' => 'Nhân viên thuê xe',

    // Chi nhánh nhận/trả xe mặc định: "89 Xuân Thủy, An Bình, Cần Thơ" (categories.id trên DB thật).
    'default_branch_id' => 203,

    // Ngày được tính giá cuối tuần (Carbon dayOfWeek: 0 = Chủ nhật, 6 = Thứ bảy).
    'weekend_days' => [6, 0],
];
