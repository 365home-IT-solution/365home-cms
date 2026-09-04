<?php

return [
    'resource' => [
        'navigation_label' => 'Loại hình phòng',
        'model_label'      => 'Loại hình phòng',
        'plural_model_label' => 'Loại hình phòng',
        'navigation_group' => 'Quản lý',
        'navigation_icon'  => 'heroicon-o-tag',
    ],
    'form' => [
        'label' => [
            'name'       => 'Tên danh mục',
            'slug'       => 'Slug',
            'icon'       => 'Icon name',
            'icon_url'   => 'URL Icon (SVG)',
            'is_active'  => 'Kích hoạt',
            'sort_order' => 'Thứ tự',
        ],
        'placeholder' => [
            'name'     => 'VD: Theo giờ',
            'slug'     => 'theo_gio',
            'icon'     => 'VD: checkmark',
            'icon_url' => 'https://cdn.example.com/icons/schedule.svg',
        ],
        'helper_text' => [
            'icon' => 'Chỉ nhập tên icon — VD: checkmark, schedule, bed, home',
        ],
    ],
    'table' => [
        'label' => [
            'name'       => 'Tên danh mục',
            'slug'       => 'Slug',
            'icon'       => 'Icon',
            'is_active'  => 'Kích hoạt',
            'sort_order' => 'Thứ tự',
            'created_at' => 'Ngày tạo',
        ],
    ],
];
