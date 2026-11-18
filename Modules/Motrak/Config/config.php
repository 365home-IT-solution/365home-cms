<?php

return [
    'name' => 'Motrak',

    // Endpoint ĐÃ KIỂM CHỨNG thực tế (POST, Authorization: Bearer, Accept: application/json, không body). KHÔNG đổi phương thức/xác thực.
    // Super Admin không sửa được endpoint trên CMS; chỉ đổi qua biến môi trường khi Motrak đổi địa chỉ chính thức.
    'endpoint' => env('MOTRAK_ENDPOINT', 'https://api.motrak.vn/client/v2/api/v1/monitor/refreshvehicles'),

    'http' => [
        'timeout'         => 15,        // giây
        'connect_timeout' => 5,         // giây
        'retries'         => 2,         // số lần THỬ LẠI tối đa (ngoài lần gọi đầu) — chỉ với lỗi mạng tạm thời / 5xx / 429
        'backoff_ms'      => [500, 1500], // chờ trước mỗi lần thử lại
    ],

    'sync' => [
        'default_interval_minutes' => 1,
        'allowed_intervals'        => [1, 2, 3, 5, 10, 15, 30],
        'lock_seconds'             => 120, // khoá chống 2 lần đồng bộ chạy đồng thời
    ],

    // Độ tươi của dữ liệu. isConnected CHƯA được Motrak xác nhận ý nghĩa nên không dùng một mình để kết luận xe offline:
    // trạng thái hiển thị = (có dữ liệu?) + (dữ liệu có quá cũ?) + (isConnected thô).
    'freshness' => [
        'stale_after_minutes' => 10,
        'moving_speed_kmh'    => 3,   // tốc độ từ ngưỡng này trở lên = đang di chuyển
    ],

    // Chính sách lưu lịch sử (bảng motrak_vehicle_locations). endpoint chỉ trả điểm MỚI NHẤT nên lịch sử chỉ gồm các điểm 365Home đã đồng bộ.
    'history' => [
        'retention_days'              => 90,
        'sync_logs_retention_days'    => 90,
        'min_interval_minutes'        => 10, // xe đứng yên: tối đa 1 điểm / khoảng này
        'min_distance_meters'         => 50, // xe chạy: lưu điểm mới khi đã dịch chuyển tối thiểu từng này
        'max_page_size'               => 500,
    ],

    // Cách giải mã thời gian (SUY LUẬN từ dữ liệu mẫu, cần Motrak xác nhận): utcTicks = .NET ticks (100ns từ 0001-01-01 UTC) — dùng làm giờ chuẩn;
    // "time" = giây kể từ 2010-01-01 00:00 theo giờ địa phương UTC+7 — chỉ dùng dự phòng khi thiếu utcTicks.
    'time' => [
        'device_epoch'            => '2010-01-01 00:00:00',
        'device_utc_offset_hours' => 7,
    ],

    // Vai trò xem dữ liệu GPS (không được đổi Token). Tạo bởi migration create_motrak_tables.
    'viewer_role' => 'Quản lý GPS',
];
