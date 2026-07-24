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
        'Quản lý vận hành / Booking' => ['BOOKING', 'Chi nhánh và khu vực', 'Thiết lập Phòng', 'Mật khẩu khóa thủ công', 'Khai báo lưu trú'],
        'Giá & Khuyến mãi' => ['Hệ thống giá', 'Ưu đãi', 'Mã giảm giá', 'Pass Cổng'],
        'Nội dung & Marketing' => ['Bài viết', 'Dịch vụ Bổ sung'],
        'Chăm sóc khách hàng' => ['Tư vấn khách hàng', 'Tin nhắn khách hàng'],
        'Đối tác & Báo cáo' => ['Đối tác', 'Thống kê lương'],
    ],

    'Cấu hình web' => [
        'Cấu hình chung' => ['Chung', 'Chi nhánh', 'Thông tin công ty'],
        'Giao diện & Nội dung Website' => ['Thành phần', 'Menu', 'Trang', 'Theme', 'Thư viện'],
        'Thanh toán & Tích hợp bên thứ ba' => ['Thanh toán online', 'Tài khoản TTLock', 'Email'],
    ],

    'Phân quyền' => [
        'Vai trò & Phân quyền' => ['Vai trò', 'Phân quyền dữ liệu', 'Phân quyền Chi nhánh'],
        'Thành viên nội bộ' => ['Thành viên', 'Lịch sử thao tác'],
        'Khách hàng bên ngoài' => ['Khách hàng', 'Hạng thành viên'],
    ],

    'Quản lý API' => [
        'Thông tin & Cấu hình Phòng' => ['Danh mục Phòng', 'Ảnh Phòng', 'Điểm Đặc Biệt', 'Tiện ích Phòng', 'Gán Tiện Ích Phòng', 'Dịch vụ Phòng'],
        'Vận hành Phòng' => ['Kiểm tra dọn phòng', 'Khách vãng lai'],
        'Nội dung & Giao diện App' => ['Trang App', 'Banner'],
        'Thông báo & Dữ liệu hệ thống' => ['Push Notification', 'Thông báo vào app', 'Tỉnh/Thành phố'],
    ],
];
