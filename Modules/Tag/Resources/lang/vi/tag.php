<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-cube-transparent',
        'navigation_group' => 'Quản lý',
        'navigation_label' => 'Tiện ích',
        'model_label' => 'Tiện ích',
        'plural_model_label' => 'Tiện ích',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên Tiện ích',
            'slug' => 'Đường dẫn',
            'image' => 'Hình ảnh tiện nghi'
        ],
        'placeholder' => [
            'name' => 'Nhập tên Tiện ích...',
            'slug' => 'Tự động tạo từ tên Tiện ích...'
        ]
    ],
    'table' => [
        'label' => [
            'image' => 'Hình ảnh',
            'name' => 'Tên Tiện ích',
            'usage_count' => 'Số lượng sử dụng',
            'usage_details' => 'Chi tiết sử dụng',
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
