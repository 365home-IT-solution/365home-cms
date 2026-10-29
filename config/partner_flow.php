<?php

// LUỒNG ĐỐI TÁC
// MiniHouse: chỉ MUA GÓI rồi dùng (không đăng ký đối tác, không ký hợp đồng). Phần hợp đồng/hồ sơ pháp lý của MiniHouse được ẨN (code vẫn giữ);
// đặt MINIHOUSE_CONTRACT_ENABLED=true để bật lại. Homestay vẫn đăng ký → duyệt hồ sơ → ký hợp đồng như cũ.
return [
    'minihouse_contract_enabled' => (bool) env('MINIHOUSE_CONTRACT_ENABLED', false),

    // MiniHouse: đăng ký lần đầu được TẶNG dùng thử N tháng sau khi Super Admin DUYỆT (tài khoản + mật khẩu gửi qua email lúc duyệt); 0 = tắt,
    // khi đó phải thanh toán gói mới được dùng. Thanh toán trước khi được duyệt cũng kích hoạt luôn.
    'minihouse_signup_trial_months' => (int) env('MINIHOUSE_SIGNUP_TRIAL_MONTHS', 1),

    // MiniHouse ĐĂNG KÝ DÙNG THỬ trên website: BẮT BUỘC nộp đủ giấy tờ pháp lý cấp đối tác (danh sách ở registration_required_documents bên dưới)
    // và được Super Admin duyệt thì mới được tặng dùng thử + cấp tài khoản. Nhánh thanh toán gói ngay không đổi. false = duyệt dùng thử không cần giấy tờ.
    'minihouse_trial_documents_required' => (bool) env('MINIHOUSE_TRIAL_DOCUMENTS_REQUIRED', true),

    // Giấy tờ BẮT BUỘC khi đăng ký hợp tác trên website/API, theo loại đối tác (cách nhau bằng dấu phẩy; Giấy phép kinh doanh luôn bắt buộc).
    // Hiện tại: MiniHouse cần ĐKKD + CCCD; Homestay cần ĐKKD + CCCD + ANTT. Loại không bắt buộc (vd PCCC) vẫn nộp được như giấy tờ tuỳ chọn.
    // Thêm PCCC vào bắt buộc: nối ,fire_safety vào biến tương ứng. Bỏ CCCD: xoá citizen_id khỏi biến.
    // Loại hợp lệ: business_license (ĐKKD), citizen_id (CCCD — phải đọc được mã QR trên thẻ), security_order (ANTT), fire_safety (PCCC)
    // — PartnerLegalDocument::REGISTRATION_REQUIRED.
    'registration_required_documents' => [
        'homestay'  => array_filter(array_map('trim', explode(',', (string) env('HOMESTAY_REGISTRATION_DOCUMENTS', 'business_license,citizen_id,security_order')))),
        'minihouse' => array_filter(array_map('trim', explode(',', (string) env('MINIHOUSE_REGISTRATION_DOCUMENTS', 'business_license,citizen_id')))),
    ],

    // Loại giấy tờ HIỂN THỊ trên trang đăng ký hợp tác (web) ngoài các loại bắt buộc ở trên — mỗi loại một thẻ tải lên.
    // Các loại khác (thuế, cơ sở lưu trú, uỷ quyền, quyền khai thác toà nhà, giấy tờ khác) đang ẨN; thêm mã loại vào biến này để hiện lại,
    // vd REGISTRATION_SELECTABLE_DOCUMENTS=fire_safety,tax_registration,other. API và trang quản trị vẫn nhận mọi loại.
    'registration_selectable_documents' => array_filter(array_map('trim', explode(',', (string) env('REGISTRATION_SELECTABLE_DOCUMENTS', 'fire_safety')))),

    // THỨ TỰ KÝ HỢP ĐỒNG của đối tác đăng ký trên website (Homestay; MiniHouse nếu bật MINIHOUSE_CONTRACT_ENABLED):
    //  true  (mặc định) = ĐỐI TÁC KÝ TRƯỚC: nộp giấy tờ → điền thông tin → ký hợp đồng ĐIỀU KHOẢN CHUẨN (hoa hồng DEFAULT_COMMISSION_RATE,
    //          thời hạn CONTRACT_TERM_MONTHS) bằng OTP → hồ sơ tự gửi duyệt → 365 Home duyệt giấy tờ rồi ký phía nền tảng.
    //          Admin sửa điều khoản sau khi đối tác đã ký → tạo lại hợp đồng, đối tác ký lại bản mới.
    //  false = luồng cũ: gửi duyệt → 365 Home duyệt giấy tờ → hệ thống mới tạo và gửi hợp đồng → đối tác ký → 365 Home ký.
    'partner_signs_before_review' => (bool) env('PARTNER_SIGNS_BEFORE_REVIEW', true),

    // Tỷ lệ hoa hồng mặc định (%) của Bên A cho đối tác Homestay mới; admin vẫn sửa được trước khi tạo hợp đồng.
    'default_commission_rate' => (string) env('DEFAULT_COMMISSION_RATE', '20'),
];
