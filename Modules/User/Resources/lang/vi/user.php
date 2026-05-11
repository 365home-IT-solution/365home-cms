<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-user',
        'navigation_group' => 'Phân quyền',
        'navigation_label' => 'Thành viên',
        'model_label' => 'Thành viên',
        'plural_model_label' => 'Thành viên',
    ],
    'notifications' => [
        'verify_sent' => [
            'title' => 'Đã gửi email xác thực',
        ],
        'verify_warning' => [
            'title' => 'Chưa cấu hình email',
            'description' => 'Vui lòng cấu hình cài đặt email trước khi gửi email xác thực',
        ],
    ],
    'form' => [
        'label' => [
            'avatars' => 'Ảnh đại diện',
            'fullname' => 'Họ và tên',
            'roles' => 'Vai trò',
            'email' => 'Email',
            'password' => 'Mật khẩu',
            'password_confirmation' => 'Xác nhận mật khẩu',
        ],
        'placeholder' => [
            'fullname' => 'Nhập họ và tên...',
            'email' => 'Nhập email...',
            'password' => 'Nhập mật khẩu...',
            'password_confirmation' => 'Nhập lại mật khẩu...',
            'fullname' => 'Nhập họ và tên...',
        ],
        'action' =>[
            'label'=>[
                'password' => 'Mật khẩu mới',
                'confirm_password' => 'Nhập lại mật khẩu mới'
            ],
            'update_password' => 'Cập nhật mật khẩu',
            'submit' => 'Gửi',
            'cancel' => 'Hủy'
        ],
        'more_action' =>[
            'more_action' => 'Hành động',
            'change_password' => 'Đổi mật khẩu',
            'create' => 'Thêm người dùng'
        ]
    ],
    'table' => [
        'label' => [
            'avatars' => 'Ảnh đại diện',
            'fullname' => 'Họ và tên',
            'email' => 'Email',
            'roles' => 'Vai trò',
            'verified_at' => 'Đã xác minh tại',
            'created_at' => 'Ngày thêm',
        ],

    ],
    'filter' => [
        'label' => [
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày',
        ],
    ],
];
