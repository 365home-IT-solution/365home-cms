<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Trang Mẫu
    |--------------------------------------------------------------------------
    */
    // 'page' => [
    //     'title' => 'Tiêu đề Trang',
    //     'heading' => 'Đề mục Trang',
    //     'subheading' => 'Đề mục phụ Trang',
    //     'navigationLabel' => 'Nhãn Điều hướng Trang',
    //     'section' => [],
    //     'fields' => []
    // ],

    /*
    |--------------------------------------------------------------------------
    | Cài đặt Chung
    |--------------------------------------------------------------------------
    */
    'general_settings' => [
        'title' => 'Cài đặt chung',
        'heading' => 'Cài đặt chung',
        'subheading' => 'Quản lý cài đặt chung của trang tại đây.',
        'navigationLabel' => 'Chung',
        'sections' => [
            "site" => [
                "title" => "Trang web",
                "description" => "Quản lý cài đặt cơ bản."
            ],
            "theme" => [
                "title" => "Giao diện",
                "description" => "Thay đổi giao diện mặc định."
            ],
        ],
        "fields" => [
            "brand_name" => "Tên Thương hiệu",
            "site_active" => "Trạng thái Trang web",
            "brand_logoHeight" => "Chiều cao Logo Thương hiệu",
            "brand_logo" => "Logo Thương hiệu",
            "site_favicon" => "Biểu tượng Trang web",
            "primary" => "Primary color",
            "secondary" => "Secondary color",
            "gray" => "Gray color",
            "success" => "Success color",
            "danger" => "Danger color",
            "info" => "Info color",
            "warning" => "Waring color",
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Cài đặt Email
    |--------------------------------------------------------------------------
    */
    'mail_settings' => [
        'title' => 'Cài đặt Email',
        'heading' => 'Cài đặt Email',
        'subheading' => 'Quản lý cấu hình email.',
        'navigationLabel' => 'Email',
        'sections' => [
            "config" => [
                "title" => "Cấu hình",
                "description" => "mô tả"
            ],
            "sender" => [
                "title" => "Người gửi (From)",
                "description" => "mô tả"
            ],
            "mail_to" => [
                "title" => "Gửi đến",
                "description" => "mô tả"
            ],
        ],
        "fields" => [
            "placeholder" => [
                "receiver_email" => "Email người nhận.."
            ],
            "driver" => "Trình điều khiển",
            "host" => "Máy chủ",
            "port" => "Cổng",
            "encryption" => "Mã hóa",
            "timeout" => "Thời gian chờ",
            "username" => "Tên đăng nhập",
            "password" => "Mật khẩu",
            "email" => "Email",
            "name" => "Tên",
            "mail_to" => "Gửi đến",
        ],
        "actions" => [
            "send_test_mail" => "Gửi Email Thử nghiệm"
        ]
    ],
];
