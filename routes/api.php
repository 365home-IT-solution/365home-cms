<?php

use App\Http\Controllers\Api\Auth\ZaloOtpController;
use App\Http\Controllers\Api\LockRecordCallbackController;
use App\Http\Controllers\Api\RoomTypeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TTLock Callback
| Callback URL cần đăng ký trong TTLock Management Center > Application
| URL: https://365home.vn/api/lock/callback
|--------------------------------------------------------------------------
*/
Route::post('lock/callback', [LockRecordCallbackController::class, 'handle'])
    ->name('lock.callback');

/*
|--------------------------------------------------------------------------
| Zalo OTP Auth
| POST /api/auth/send-otp   → Gửi OTP về Zalo của khách
| POST /api/auth/verify-otp → Xác nhận OTP, trả Sanctum token
| POST /api/auth/logout     → Đăng xuất (xoá token hiện tại)
| GET  /api/auth/me         → Thông tin user đang đăng nhập
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Room Types
| GET /api/room-types → Danh sách danh mục phòng (dùng cho select/dropdown)
|--------------------------------------------------------------------------
*/
Route::get('room-types', [RoomTypeController::class, 'index'])->name('api.room-types.index');

Route::prefix('auth')->name('api.auth.')->group(function () {
    // Đăng nhập
    Route::post('send-otp',   [ZaloOtpController::class, 'sendOtp'])->name('send-otp')->middleware('throttle:otp-send');
    Route::post('verify-otp', [ZaloOtpController::class, 'verifyOtp'])->name('verify-otp')->middleware('throttle:otp-verify');

    // Đăng ký
    Route::post('register', [ZaloOtpController::class, 'register'])->name('register')->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [ZaloOtpController::class, 'logout'])->name('logout');
        Route::get('me',      [ZaloOtpController::class, 'me'])->name('me');
    });
});

