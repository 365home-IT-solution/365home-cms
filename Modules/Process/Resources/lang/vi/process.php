<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-cog',
        'navigation_group' => 'Nội dung',
        'navigation_label' => 'Quản lí quy trình',
        'model_label' => 'Quy trình',
        'plural_model_label' => 'Quy trình',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên quy trình',
            'description' => 'Mô tả',
            'steps' => 'Các bước',
            'step_name' => 'Tên bước',
            'step_description' => 'Mô tả bước',
        ],
        'placeholder' => [
            'name' => 'Nhập tên quy trình...',
            'description' => 'Nhập mô tả cho quy trình...',
            'step_name' => 'Nhập tên bước...',
            'step_description' => 'Nhập mô tả bước...',
        ],
        'button' => [
            'add_step' => 'Thêm bước mới',
        ],
    ],
    'table' => [
        'label' => [
            'name' => 'Tên quy trình',
            'steps_count' => 'Số bước',
            'created_at' => 'Ngày thêm',
        ],
    ],
];