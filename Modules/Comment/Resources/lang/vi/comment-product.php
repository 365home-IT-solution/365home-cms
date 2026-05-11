<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-cube',
        'navigation_group' => 'Bình luận',
        'navigation_label' => 'Bình luận phòng',
        'model_label' => 'Bình luận sản phẩm',
        'plural_model_label' => 'Bình luận sản phẩm',
        'commentable_type' => 'Modules\Product\App\Models\Product'
    ],
    'form' => [
        'label' => [
            'name' => 'Họ và tên',
            'commentable_id' => 'Tên sản phẩm',
            'show' => 'Ẩn / Hiện bình luận',
            'pin' => 'Ghim bình luận',
            'text' => 'Nội dung',
            'account_id' => 'Người tạo bình luận'
        ],
        'hidden' => [
            'commentable_type' => 'Modules\Product\App\Models\Product',
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
            'titleProduct' => 'Sản phẩm',
            'name' => 'Người bình luận',
            'text' => 'Nội dung',
            'show' => 'Trạng thái',
            'replies_count' => 'Số người phản hồi',
            'created_at' => 'Ngày tạo',
        ],
        'actions' => [
            'create' => 'Tạo bình luận sản phẩm',
        ],
        'defaultSort' => [
            'columnToSort' => 'created_at',
            'chooseSort' => 'desc'
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
            'commentable_type' => 'Modules\Product\App\Models\Product'
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
