<?php

// LUỒNG ĐỐI TÁC
// MiniHouse: chỉ MUA GÓI rồi dùng (không đăng ký đối tác, không ký hợp đồng). Phần hợp đồng/hồ sơ pháp lý của MiniHouse được ẨN (code vẫn giữ);
// đặt MINIHOUSE_CONTRACT_ENABLED=true để bật lại. Homestay vẫn đăng ký → duyệt hồ sơ → ký hợp đồng như cũ.
return [
    'minihouse_contract_enabled' => (bool) env('MINIHOUSE_CONTRACT_ENABLED', false),

    // Tỷ lệ hoa hồng mặc định (%) của Bên A cho đối tác Homestay mới; admin vẫn sửa được trước khi tạo hợp đồng.
    'default_commission_rate' => (string) env('DEFAULT_COMMISSION_RATE', '20'),
];
