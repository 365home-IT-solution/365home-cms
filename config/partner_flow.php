<?php

// LUỒNG ĐỐI TÁC
// MiniHouse: chỉ MUA GÓI rồi dùng (không đăng ký đối tác, không ký hợp đồng). Phần hợp đồng/hồ sơ pháp lý của MiniHouse được ẨN (code vẫn giữ);
// đặt MINIHOUSE_CONTRACT_ENABLED=true để bật lại. Homestay vẫn đăng ký → duyệt hồ sơ → ký hợp đồng như cũ.
return [
    'minihouse_contract_enabled' => (bool) env('MINIHOUSE_CONTRACT_ENABLED', false),

    // MiniHouse: đăng ký lần đầu được TẶNG dùng thử N tháng sau khi Super Admin DUYỆT (tài khoản + mật khẩu gửi qua email lúc duyệt); 0 = tắt,
    // khi đó phải thanh toán gói mới được dùng. Thanh toán trước khi được duyệt cũng kích hoạt luôn.
    'minihouse_signup_trial_months' => (int) env('MINIHOUSE_SIGNUP_TRIAL_MONTHS', 1),

    // MiniHouse ĐĂNG KÝ DÙNG THỬ trên website: BẮT BUỘC nộp đủ 3 giấy tờ pháp lý cấp đối tác như Homestay (Giấy phép kinh doanh, ANTT, PCCC)
    // và được Super Admin duyệt thì mới được tặng dùng thử + cấp tài khoản. Nhánh thanh toán gói ngay không đổi. false = duyệt dùng thử không cần giấy tờ.
    'minihouse_trial_documents_required' => (bool) env('MINIHOUSE_TRIAL_DOCUMENTS_REQUIRED', true),

    // Tỷ lệ hoa hồng mặc định (%) của Bên A cho đối tác Homestay mới; admin vẫn sửa được trước khi tạo hợp đồng.
    'default_commission_rate' => (string) env('DEFAULT_COMMISSION_RATE', '20'),
];
