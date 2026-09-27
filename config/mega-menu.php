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
        'Quản lý vận hành' => ['Đơn phòng', 'Chi nhánh', 'Phòng', 'Loại hình phòng', 'Dịch vụ', 'Tiện ích', 'Tỉnh/Thành phố'],
        'Quản lý giá & khuyến mãi' => ['Hệ thống giá', 'Bảng giá', 'Khuyến mãi & Giảm giá'],
        'Nội dung & Marketing' => ['Bài viết', 'Danh mục bài viết'],
        'Quản lý khách hàng' => ['Tin nhắn', 'Khách hàng', 'Hạng thành viên', 'Tư vấn khách hàng'],
        'Quản lý kho' => ['Danh mục vật tư', 'Phiếu nhập kho', 'Phiếu xuất kho', 'Phiếu hoàn trả kho', 'Phiếu kiểm kê', 'Nhóm vật tư', 'Đơn vị tính'],
        'Thông báo' => ['Gửi thông báo đến khách'],
        'Đối tác & Báo cáo' => ['Đối tác', 'Thống kê lương'],
    ],

    'Cấu hình web' => [
        'Cấu hình chung' => ['Chung', 'Ẩn chi nhánh', 'Thông tin công ty'],
        'Pass cổng' => ['Khóa thủ công', 'Pass Cổng'],
        'Giao diện website' => ['Menu', 'Trang', 'Thư viện', 'Banner'],
        'Thanh toán & Tích hợp bên thứ ba' => ['Thanh toán online', 'Tài khoản TTLock', 'Email'],
    ],

    'Phân quyền' => [
        'Vai trò & Phân quyền' => ['Vai trò', 'Phân quyền Chi nhánh'],
        'Thành viên nội bộ' => ['Thành viên', 'Lịch sử thao tác'],
    ],

    'Quản lý API' => [
        'Nội dung app' => ['APP', 'POPUP'],
    ],

    // Bảng RIÊNG từng panel — ưu tiên hơn bảng chung ở trên (xem topbar/index.blade.php). Panel
    // MiniHouse (cho thuê theo tháng) có bộ mục khác hẳn Home nên gom theo đúng nghiệp vụ của nó;
    // mục không khớp bảng nào vẫn rơi vào cột "Khác", không bị mất khỏi menu.
    'panels' => [
        'minihouse-admin' => [
            'Quản lý' => [
                'Vận hành toà nhà' => ['Khu vực', 'Toà nhà', 'Phòng', 'Tiện ích', 'Loại tài sản', 'Phụ thu', 'Sơ đồ 360°'],
                'Khách thuê & Hợp đồng' => ['Khách thuê', 'Hợp đồng', 'Khai báo lưu trú', 'Yêu cầu liên hệ thuê phòng', 'Phản hồi khách thuê'],
                'Tài chính' => ['Hoá đơn', 'Số điện nước', 'Sổ thu chi', 'Thu chi & Báo cáo'],
                'Kho vật tư' => ['Danh mục vật tư', 'Phiếu nhập kho', 'Phiếu xuất kho', 'Phiếu hoàn trả kho', 'Phiếu kiểm kê', 'Nhóm vật tư', 'Đơn vị tính'],
                'Liên lạc & Thông báo' => ['Tin nhắn', 'Thông báo', 'Thông báo đẩy', 'Nhắc việc'],
                'An ninh' => ['Camera', 'Xem camera'],
            ],
            'Phân quyền' => [
                'Vai trò & Tài khoản' => ['Vai trò', 'Tài khoản'],
            ],
            'Hệ thống' => [
                'Nhật ký' => ['Nhật ký hoạt động'],
                'Kênh gửi thông báo' => ['Cấu hình Zalo', 'Cấu hình SMS'],
                'Camera' => ['Cấu hình Camera'],
            ],
        ],
    ],
];
