<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-rectangle-stack',
        'navigation_group' => 'Nội dung',
        'navigation_label' => 'Bình luận bài viết',
        'model_label' => 'Bình luận bài viết',
        'plural_model_label' => 'Bình luận bài viết',
        'commentable_type' => 'Modules\Post\Entities\Post'
    ],
    'form' => [
        'label' => [
            'name' => 'Họ và tên',
            'commentable_id' => 'Tên bài viết',
            'show' => 'Ẩn / Hiện bình luận',
            'pin' => 'Ghim bình luận',
            'text' => 'Nội dung',
            'account_id' => 'Người tạo bình luận'
        ],
        'hidden' => [
            'commentable_type' => 'Modules\Post\Entities\Post',
        ],
        'replies' => [
                'name' => 'Người phản hồi',
                'show' => 'Ẩn / Hiện bình luận',
                'pin' => 'Ghim bình luận'
        ],
        'icons' => [
            'showOn' => 'heroicon-o-eye',
            'showOff' => 'heroicon-o-eye-slash',
            'pinOn' => 'heroicon-m-bookmark',
            'pinOff' => 'heroicon-m-bookmark-slash'
        ]
    ],
    'table' => [
        'label' => [
            'titlePost' => 'Bài viết',
            'name' => 'Người bình luận',
            'text' => 'Nội dung',
            'show' => 'Trạng thái',
            'replies_count' => 'Số người phản hồi',
            'created_at' => 'Ngày tạo',
        ],
        'actions' => [
            'create' => 'Tạo bình luận bài viết',
        ],
        'defaultSort' => [
            'columnToSort' => 'created_at',
            'chooseSort' => 'desc'
        ],
        'options' => [
            'showOn' => 'Hiển thị',
            'showOff' => 'Ẩn'
        ],
        'icons' => [
            'showOn' => 'heroicon-o-eye',
            'showOff' => 'heroicon-o-eye-slash',
            'link' => 'heroicon-m-link',
            'plus' => 'heroicon-m-plus'
        ],
        'filterForm' => [
            'sizeModal' => '4xl',
            'columnModal' => 12
        ],
        'query' => [
            'commentable_type' => 'Modules\Post\Entities\Post'
        ]
    ],
    'filter' => [
        'label' => [
            'pin' => 'Ghim',
            'created_at' => 'Ngày tạo'
        ],
        'options' => [
            'pinOn' => 'Bình luận có ghim',
            'pinOff' => 'Bình luận không có ghim',
        ],
    ],
];
