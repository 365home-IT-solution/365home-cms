<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Van xả khẩn cấp cho refresh_token Zalo OA MiniHouse
    |--------------------------------------------------------------------------
    | KHÔNG xuất hiện ở bất kỳ đâu trong panel admin (khác các field ở ZaloSettingsPage — App ID/
    | App Secret/Refresh Token lưu trong bảng minihouse_zalo_settings) — chỉ set được bằng cách sửa
    | biến môi trường MINIHOUSE_ZALO_REFRESH_TOKEN_OVERRIDE trên server, dành cho người quản trị hạ
    | tầng khi refresh_token lưu trong DB bị Zalo thu hồi (lỗi "Invalid refresh token.") và cần khôi
    | phục ngay mà chưa kịp/không tiện vào panel dán lại.
    |
    | Dùng 1 LẦN DUY NHẤT: MinihouseZaloTokenService::refresh() ưu tiên đọc giá trị này thay cho
    | ZaloSetting->refresh_token nếu có set — ngay khi refresh thành công, token MỚI Zalo cấp lại vẫn
    | được lưu THẲNG vào DB như bình thường (không lưu lại vào .env, không cách nào lưu được), nên từ
    | request sau hệ thống tự quay về dùng DB — biến này khi đó không còn tác dụng gì nữa, xoá khỏi
    | .env cũng được (không bắt buộc, chỉ còn ý nghĩa "đợi dùng khi cần" cho lần sau).
    |--------------------------------------------------------------------------
    */
    'refresh_token_override' => env('MINIHOUSE_ZALO_REFRESH_TOKEN_OVERRIDE'),

];
