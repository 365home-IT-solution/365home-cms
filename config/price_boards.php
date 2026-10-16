<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Bảng giá v2 — giai đoạn 2
    |--------------------------------------------------------------------------
    | Bật = bảng giá có `priority` + `days_of_week`, nhiều bảng chồng nhau theo ngày, và GIÁ ĐƠN / BÁO GIÁ
    | được tính theo NGÀY KHÁCH Ở (App\Services\PriceBoards\StayPriceResolver) thay vì đọc "giá hiện tại"
    | của phòng. Tắt (mặc định) = giai đoạn 1: giá bảng ghi xuống phòng, job đêm đồng bộ, 2 bảng đang bật
    | trùng ngày cho cùng phòng thì 422.
    |
    | CHỈ bật khi đã kiểm thử đặt phòng — đổi cờ này đổi cách tính tiền của mọi đơn MỚI (đơn cũ giữ giá).
    */
    'phase2' => (bool) env('PRICE_BOARDS_PHASE2', false),
];
