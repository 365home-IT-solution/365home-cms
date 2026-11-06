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

// Phòng Tiến lên nhiều người: chỉ khách đang có ghế trong phòng. Xác thực qua POST /api/broadcasting/auth (Sanctum, xem routes/api.php).
// Kênh chỉ báo "phòng vừa đổi" — không mang bài (App\Events\TienLenRoomUpdated).
Broadcast::channel('tien-len.room.{roomId}', function ($user, string $roomId) {
    return $user instanceof \App\Models\Customer
        && \App\Models\TienLenRoomSeat::where('room_id', $roomId)->where('customer_id', $user->id)->exists();
});
