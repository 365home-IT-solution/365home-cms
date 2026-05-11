<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-check-badge',
        'navigation_group' => 'Quản lý',
        'navigation_label' => 'Bài viết',
        'model_label' => 'Bài viết',
        'plural_model_label' => 'Bài viết',
    ],
    'form' => [
        'label' => [
            'title' => 'Tiêu đề',
            'slug' => 'Đường dẫn',
            'summary' => 'Tóm tắt',
            'content' => 'Nội dung bài viết',
            'tags' => 'Thẻ',
            'categories' => 'Danh mục',
            'post_image' => 'Hình ảnh',
            'seo_title' => 'SEO tiêu đề',
            'seo_description' => 'SEO mô tả',
            'seo_keywords' => 'Từ khóa',
            'published_at' => 'Ngày công khai',
            'status' => 'Trạng thái',
        ],
        'placeholder' => [
            'title' => 'Nhập tiêu đề bài viết...',
            'slug' => 'Tự động tạo từ tên bài viết...',
            'summary' => 'Nhập tóm tắt cho bài viết...',
            'tags' => 'Chọn hoặc thêm thẻ...',
            'categories' => 'Tìm kiếm...',
            'seo_title' => 'Tự động tạo từ tiêu đề bài viết...',
            'seo_description' => 'SEO mô tả không quá 160 ký tự...',
            'seo_keywords' => 'Từ khóa cách nhau bởi dấu phẩy...',
        ],
        'options' => [
            'status' => [
                'draft' => 'Nháp',
                'published' => 'Xuất bản',
                'archived' => 'Lưu trữ'
            ],
        ],
        'icons' => [
            'status' => [
                'draft' => 'heroicon-o-pencil-square',
                'published' => 'heroicon-o-check-circle',
                'archived' => 'heroicon-o-truck',
            ],
        ],
        'colors' => [
            'status' => [
                'draft' => 'warning',
                'published' => 'success',
                'archived' => 'danger',
            ],
        ],
    ],
    'table' => [
        'label' => [
            'title' => 'Tiêu đề',
            'url' => 'Đường dẫn bài viết',
            'user' => 'Người đăng',
            'tags' => 'Thẻ',
            'categories' => 'Danh mục',
            'post_image' => 'Hình ảnh',
            'status' => 'Trạng thái',
            'created_at' => 'Ngày đăng',
        ],
        'options' => [
            'status' => [
                'draft' => 'Nháp',
                'published' => 'Xuất bản',
                'archived' => 'Lưu trữ',
            ],
        ],
        'icons' => [
            'status' => [
                'draft' => 'heroicon-s-pencil-square',
                'published' => 'heroicon-s-check-circle',
                'archived' => 'heroicon-s-archive-box',
            ],
        ],
        'colors' => [
            'status' => [
                'draft' => 'gray',
                'published' => 'success',
                'archived' => 'warning',
            ],
        ],
        'tooltips' => [
            'status' => [
                'draft' => 'Bài viết hiện đang là bản nháp.',
                'published' => 'Bài viết này đã được xuất bản.',
                'archived' => 'Bài viết này đã được lưu trữ.',
            ],
        ],
        'status' => [
            'draft' => 'Nháp',
            'published' => 'Đã xuất bản',
            'archived' => 'Đã lưu trữ',
            'scheduled' => 'Theo lịch trình'
        ],
    ],
    'filter' => [
        'label' => [
            'categories' => 'Danh mục',
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày',
            'status' => 'Trạng thái',
        ],
    ],
];