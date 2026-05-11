<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-square-3-stack-3d',
        'navigation_group' => 'Cấu hình web',
        'navigation_label' => 'Trang',
        'model_label' => 'Trang',
        'plural_model_label' => 'Trang',
    ],
    'form' => [
        'label' => [
            'title' => 'Tiêu đề',
            'seo_title' => 'SEO tiêu đề',
            'seo_description' => 'SEO mô tả',
            'seo_keywords' => 'Từ khóa',
            'components' => 'Thành phần',
            'component_id' => 'Thành phần',
        ],
        'placeholder' => [
            'title' => 'Nhập tiêu đề...',
            'seo_title' => 'SEO tiêu đề không quá 60 ký tự...',
            'seo_description' => 'SEO mô tả không quá 160 ký tự...',
            'seo_keywords' => 'Từ khóa cách nhau bởi dấu phẩy...',
        ],
        'section' => [
            'content' => 'Nội dung trang',
        ],
        'action' => [
            'add_component' => 'Thêm thành phần',
        ],
    ],
    'table' => [
        'label' => [
            'title' => 'Tiêu đề',
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