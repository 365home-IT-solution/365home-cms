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

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
