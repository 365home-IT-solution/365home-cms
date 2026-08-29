<?php

namespace App\Exceptions;

use BezhanSalleh\FilamentExceptions\FilamentExceptions;
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
    }
}
