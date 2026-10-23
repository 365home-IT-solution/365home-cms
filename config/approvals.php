<?php

// Duyệt trước khi đăng (đối tác gửi 365home duyệt: bảng giá, phòng) — xem App\Services\ApprovalService.
// Tắt (APPROVALS_ENABLED=false) thì mọi thay đổi của đối tác có hiệu lực ngay như trước; dữ liệu đã gửi duyệt vẫn giữ nguyên.
return [
    'enabled' => (bool) env('APPROVALS_ENABLED', true),
];
