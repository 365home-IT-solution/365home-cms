<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Kênh private DUY NHẤT cho toàn bộ hold khung giờ real-time ở admin (xem TimeslotHoldService) —
// Broadcast::routes() mặc định xác thực qua guard 'web' (App\Models\User, xem config/auth.php),
// khách hàng ở web đặt phòng dùng Customer model/JWT riêng (CustomerJwtMiddleware) nên KHÔNG bao
// giờ xác thực được ở đây — tự nhiên chỉ tài khoản admin/nhân viên mới join được kênh này.
Broadcast::channel('admin-timeslot-holds', function (User $user) {
    return true;
});
