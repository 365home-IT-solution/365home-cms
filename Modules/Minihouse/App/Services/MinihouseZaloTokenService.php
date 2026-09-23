<?php

namespace Modules\Minihouse\App\Services;

use App\Services\ZaloTokenService;

// BUG THẬT đã gặp (2026-09-22): bản trước tự quản lý access_token/refresh_token RIÊNG cho MiniHouse
// (đọc/ghi ZaloSetting, bảng minihouse_zalo_settings) dựa trên giả định MiniHouse có 1 Zalo OA KHÁC
// hẳn OA của Home — nhưng thực tế server production đang dùng CHUNG ĐÚNG 1 Zalo OA (cùng App ID) cho
// cả 2 hệ thống. Refresh_token của Zalo chỉ dùng được 1 LẦN (mỗi lần refresh thành công, Zalo THU HỒI
// token cũ, cấp token mới) — 2 nơi quản lý ĐỘC LẬP cùng cầm 1 refresh_token gốc sẽ liên tục giẫm lên
// nhau: bên nào refresh trước làm bên kia cầm token đã bị thu hồi, gây lỗi "Invalid refresh token."
// lặp lại vô tận ở CẢ 2 BÊN (kể cả khi đã dán token "mới" — đã tự xác nhận qua log 2026-09-22: sau
// khi thêm van xả MINIHOUSE_ZALO_REFRESH_TOKEN_OVERRIDE trùng giá trị ZALO_REFRESH_TOKEN của Home,
// OTP đăng ký/đăng nhập Homestay bắt đầu lỗi theo).
//
// Sửa TẬN GỐC: MiniHouse KHÔNG tự refresh/lưu token nữa — chỉ có ĐÚNG 1 nơi quản lý access_token
// (App\Services\ZaloTokenService, của Home), MiniHouse chỉ MƯỢN access_token từ đó. Class này giữ lại
// làm lớp bọc mỏng (không đổi chữ ký hàm, không phải sửa nơi gọi MinihouseZaloService) — nếu sau này
// MiniHouse có Zalo OA THẬT SỰ riêng (App ID khác), tách lại bằng cách khôi phục implementation cũ
// (xem lịch sử git) và trỏ về ZaloSetting/minihouse_zalo_settings như trước — bảng đó vẫn còn nguyên,
// chỉ không dùng cho phần app_id/app_secret/access_token/refresh_token nữa (4 mẫu ZNS Template ID vẫn
// đang dùng, xem ZaloSetting::templateFor()/template_otp).
class MinihouseZaloTokenService
{
    public function __construct(private readonly ZaloTokenService $shared)
    {
    }

    public function getAccessToken(): string
    {
        return $this->shared->getAccessToken();
    }
}
