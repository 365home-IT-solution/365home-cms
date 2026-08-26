<?php

declare(strict_types=1);

// Nhóm nhỏ hiển thị BÊN TRONG dropdown mega menu của top navigation (admin panel) — thuần
// trình bày, KHÔNG liên quan gì đến Filament ->navigationGroup() thật (những cái đó vẫn là
// "Quản lý" / "Cấu hình web" / "Phân quyền" / "Quản lý API" như cũ). Dùng bởi
// resources/views/vendor/filament-panels/components/topbar/index.blade.php để chia các mục
// trong 1 dropdown thành nhiều cột có tiêu đề (giống "Resources" / "Company" của các mega menu
// SaaS phổ biến), thay vì 1 danh sách dài duy nhất.
//
// Khớp theo navigationLabel (chuỗi hiển thị) của từng mục — không khớp được thì mục đó rơi vào
// cột "khác" ở cuối, không bị mất khỏi menu.
return [
    'Quản lý' => [
        'Quản lý vận hành' => ['Đơn phòng', 'Chi nhánh', 'Phòng', 'Dịch vụ', 'Tiện ích', 'Tỉnh/Thành phố'],
        'Quản lý giá & khuyến mãi' => ['Hệ thống giá', 'Mã giảm giá', 'Ưu đãi khung giờ'],
        'Nội dung & Marketing' => ['Bài viết', 'Danh mục bài viết'],
        'Quản lý khách hàng' => ['Tin nhắn', 'Khách hàng', 'Hạng thành viên', 'Tư vấn khách hàng'],
        'Quản lý kho' => ['Danh mục vật tư', 'Phiếu nhập kho', 'Phiếu xuất kho', 'Phiếu kiểm kê', 'Nhóm vật tư', 'Đơn vị tính'],
        'Thông báo' => ['Gửi thông báo đến khách'],
        'Đối tác & Báo cáo' => ['Đối tác', 'Thống kê lương'],
    ],

    'Cấu hình web' => [
        'Cấu hình chung' => ['Chung', 'Ẩn chi nhánh', 'Thông tin công ty'],
        'Pass cổng' => ['Khóa thủ công', 'Pass Cổng'],
        'Giao diện website' => ['Thành phần', 'Menu', 'Trang', 'Theme', 'Thư viện', 'Banner'],
        'Thanh toán & Tích hợp bên thứ ba' => ['Thanh toán online', 'Tài khoản TTLock', 'Email'],
    ],

    'Phân quyền' => [
        'Vai trò & Phân quyền' => ['Vai trò', 'Phân quyền Chi nhánh'],
        'Thành viên nội bộ' => ['Thành viên', 'Lịch sử thao tác'],
    ],

    'Quản lý API' => [
        'Nội dung app' => ['APP', 'POPUP'],
    ],
];
