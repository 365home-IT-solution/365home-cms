<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-map-pin',
        'navigation_group' => 'Quản lý',
        'navigation_label' => 'Chi nhánh và khu vực',
        'model_label' => 'Chi nhánh và khu vực',
        'plural_model_label' => 'Chi nhánh và khu vực',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên địa điểm',
            'slug' => 'Đường dẫn',
            'description' => 'Mô tả',
            'parent_id' => 'Thuộc chi nhánh',
            'sort_order' => 'Thứ tự hiển thị',
            'category_type' => 'Kiểu hiển thị',
            'status' => 'Trạng thái',
            'image' => 'Hình ảnh địa điểm'
        ],
        'placeholder' => [
            'name' => 'Nhập tên địa điểm...',
            'slug' => 'Tự động tạo từ tên địa điểm...',
            'description' => 'Nhập mô tả...',
            'parent_id' => 'Thuộc chi nhánh...',
            'sort_order' => '0',
            'category_type' => 'Chọn loại hiển thị...',
        ],
        'options' => [
            'product' => 'Phòng',
            'post' => 'Bài viết',
        ],
        'icons' => [
            'active' => 'heroicon-o-eye',
            'inactive' => 'heroicon-o-eye-slash'
        ]
    ],
    'table' => [
        'label' => [
            'image' => 'Hình ảnh',
            'name' => 'Tên địa điểm',
            'slug' => 'Đường dẫn',
            'parent_id' => 'Chi nhánh',
            'sort_order' => 'Thứ tự',
            'category_type' => 'Kiểu hiển thị',
            'status' => 'Trạng thái',

        ],
        'placeholder' => [
            'parent_id' => 'Chi nhánh gốc',
        ],
        'options' => [
            'product' => 'Phòng',
            'post' => 'Bài viết',
            'active' => 'Hiển thị',
            'inactive' => 'Ẩn'
        ],
        'icons' => [
            'active' => 'heroicon-o-eye',
            'inactive' => 'heroicon-o-eye-slash'
        ],
    ],
    'filter' => [
        'label' => [
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày',
            'status' => 'Lọc theo trạng thái',
            'category_type' => 'Loại theo danh mục',
            'parent' => 'Lọc theo chi nhánh'
        ],
        'options' => [
            'active' => 'Hiển thị',
            'inactive' => 'Ẩn',
            'product' => 'Phòng',
            'post' => 'Bài viết'
        ],
    ],
];
