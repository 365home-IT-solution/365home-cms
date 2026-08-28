<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;
use Modules\SettingCompany\Entities\Business;

// Trang CÔNG KHAI yêu cầu xoá tài khoản — bắt buộc bởi chính sách Data Safety của Google Play
// (mục "Xoá tài khoản" cần 1 URL công khai, truy cập được kể cả khi chưa cài app/chưa đăng nhập).
// Tự phục vụ (self-service) ngay trên trang này bằng cách gọi lại ĐÚNG các API OTP Zalo hiện có
// (POST /api/auth/send-otp, POST /api/auth/verify-otp, DELETE /api/auth/account — xem
// ZaloOtpController) từ JS phía client, không thêm endpoint/logic xoá tài khoản mới nào — tài
// khoản khách vẫn bị xoá đúng 1 luồng duy nhất (soft-delete, xem ZaloOtpController::deleteAccount()).
class DeleteAccountController extends Controller
{
    public function show(): View
    {
        return view('delete-account', [
            'business' => Business::first(),
        ]);
    }
}
