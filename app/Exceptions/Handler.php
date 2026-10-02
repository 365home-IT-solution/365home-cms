<?php

namespace App\Exceptions;

use BezhanSalleh\FilamentExceptions\FilamentExceptions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    // *** Khi nào cần debug điều chỉnh
    // public function render($request, Throwable $exception)
    // {
    //     if (config('app.debug') === true && auth()->check()) {
    //         $errorData = [
    //             'message' => $exception->getMessage(),
    //             'file' => $exception->getFile(),
    //             'line' => $exception->getLine(),
    //             'trace' => $exception->getTraceAsString(),
    //             'url' => request()->url(),
    //             'method' => request()->method(),
    //             'timestamp' => now()->format('Y-m-d H:i:s'),
    //             'user' => auth()->user()->id,
    //             'user_agent' => $request->userAgent(),
    //             'ip' => $request->ip()
    //         ];

    //         $errorId = uniqid('error_');
    //         session()->flash('error_id', $errorId);

    //         return response()->view('emails.error-report', [
    //             'errorId' => $errorId,
    //             'errorData' => $errorData
    //         ]);
    //     }

    //     return parent::render($request, $exception);
    // }

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if ($this->shouldReport($e)) {
                FilamentExceptions::report($e);
            }
        });

        // Tham số route khai báo `int $id` nhưng client gửi ID dạng chữ (vd ULID của phòng vào endpoint
        // khuyến mãi/mã giảm giá/hạng thành viên) → PHP ném TypeError tại điểm gọi controller → trước đây
        // trả 500. Đây là lỗi do người gọi (không tìm thấy tài nguyên) nên trả 404, không phải lỗi máy chủ.
        $this->renderable(function (\TypeError $e, $request) {
            if (preg_match('~Argument #\d+ \(\$\w+\) must be of type int, string given, called in .*Illuminate[\\\\/]Routing~', $e->getMessage())) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Không tìm thấy.'], 404)
                    : abort(404);
            }

            return null;
        });
    }

    /**
     * Các route dưới admin/api/* là API nội bộ cho JS polling nền (chuông đơn mới, room-cards,
     * kpi-stats...) — fetch() không tự gửi header Accept:application/json nên Laravel không coi đây
     * là request "muốn JSON". Nếu để mặc định, khi phiên đăng nhập hết hạn giữa lúc polling đang
     * chạy, Laravel sẽ redirect về trang login VÀ lưu chính URL API đó vào session làm "intended
     * URL" — lần đăng nhập kế tiếp (kể cả bằng tài khoản khác) bị đưa thẳng tới URL JSON đó thay vì
     * vào dashboard. Trả 401 JSON ngay tại đây để không bao giờ redirect/capture intended URL cho
     * nhóm route này.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('admin/api/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return parent::unauthenticated($request, $exception);
    }
}
