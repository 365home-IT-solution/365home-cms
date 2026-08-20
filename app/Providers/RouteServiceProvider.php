<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('livewire-upload', function (Request $request) {
            if ($request->user()) {
                return Limit::perMinute(200)->by('livewire_upload_user:' . $request->user()->id);
            }
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            if ($request->is('api/lock/callback')) {
                return Limit::none();
            }
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // otp-send: 5/phút/IP  +  2/phút/số điện thoại (chặn VPN rotation spam)
        RateLimiter::for('otp-send', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perMinute(2)->by('otp_send_phone:' . $request->input('phone', '')),
            ];
        });

        // otp-verify: 10/phút/IP  +  5/phút/số điện thoại
        RateLimiter::for('otp-verify', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by('otp_verify_phone:' . $request->input('phone', '')),
            ];
        });

        // register: 5/phút/IP  +  3/giờ/IP (chặn tạo hàng loạt tài khoản)
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(3)->by('register_ip:' . $request->ip()),
            ];
        });

        // config: 10/phút/IP — endpoint public trả cấu hình app (vd: Maps API key)
        RateLimiter::for('config', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // public-api: 40/phút/IP — siết chặt hơn mức mặc định 60/phút cho các endpoint
        // public "nặng" (home, search) dễ bị scraper dò toàn bộ dữ liệu phòng/giá.
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(40)->by($request->user()?->id ?: $request->ip());
        });

        // hold-slot: 15/phút/IP — time-slot-hold không yêu cầu đăng nhập (khách vãng lai cũng gọi
        // được). CHỈ là lớp phòng thủ PHỤ (rotate IP vẫn né được) — chặn DoS THẬT SỰ nằm ở trần
        // MAX_HOLDS_PER_ROOM/MAX_HOLDS_PER_SESSION trong TimeSlotHoldController::hold(), độc lập
        // với IP nên không né được bằng cách đổi IP.
        RateLimiter::for('hold-slot', function (Request $request) {
            return Limit::perMinute(15)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // API dành cho admin/nhân viên nội bộ (App\Models\User) — tách riêng khỏi
            // routes/api.php vốn phục vụ app khách hàng (customer), để 2 phía không lẫn lộn.
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_admin.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
