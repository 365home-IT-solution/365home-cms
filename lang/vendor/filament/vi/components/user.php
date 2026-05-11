<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-user',
        'navigation_group' => 'Phân quyền',
        'navigation_label' => 'Thành viên',
        'model_label' => 'Thành viên',
        'plural_model_label' => 'Thành viên',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên thành viên',
            'email' => 'Email',
            'password' => 'Mật khẩu',
            'password_confirmation' => 'Xác nhận mật khẩu',
            'roles' => 'Vai trò'
        ],
        'placeholder' => [
            'name' => 'Nhập tên thành viên...',
            'email' => 'Nhập email...',
            'password' => 'Nhập mật khẩu...',
            'password_confirmation' => 'Nhập lại mật khẩu...',
        ]
    ],
    'table' => [
        'label' => [
            'name' => 'Tên thành viên',
            'email' => 'Email',
            'roles' => 'Vai trò',
            'created_at' => 'Ngày đăng',
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
