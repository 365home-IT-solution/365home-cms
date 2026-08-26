<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-building-office-2',
        'navigation_group' => 'Cấu hình web',
        'navigation_label' => 'Ẩn chi nhánh',
        'model_label' => 'Chi nhánh',
        'plural_label' => 'Chi nhánh',
    ],
    'form' => [
        'sections' => [
            'company_info' => 'Thông tin công ty',
            'branch_info' => 'Thông tin chi nhánh',
        ],
        'label' => [
            'business' => [
                'label' => 'Công ty',
                'placeholder' => 'Chọn công ty',
            ],
            'name' => [
                'label' => 'Tên chi nhánh',
                'placeholder' => 'Nhập tên chi nhánh...',
            ],
            'address' => [
                'label' => 'Địa chỉ',
                'placeholder' => 'Nhập địa chỉ...',
            ],
            'phone' => [
                'label' => 'Số điện thoại',
                'placeholder' => 'Nhập số điện thoại...',
            ],
            'email' => [
                'label' => 'Email',
                'placeholder' => 'Nhập email...',
            ],
            'status' => [
                'label' => 'Trạng thái hoạt động',
                'options' => [
                    'active' => 'Hoạt động',
                    'inactive' => 'Ngừng hoạt động',
                ],
            ],
        ],
    ],
    'table' => [
        'columns' => [
            'id' => 'ID',
            'name' => 'Tên chi nhánh',
            'email' => 'Email',
            'phone' => 'Số điện thoại',
            'address' => 'Địa chỉ',
            'status' => 'Trạng thái',
        ],
        'icons' => [
            'active' => 'heroicon-o-check-circle',
            'inactive' => 'heroicon-o-x-circle',
        ],
    ],
    'filter' => [
        'label' => [
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày',
            'status' => 'Trạng thái',
        ],
        'options' => [
            'active' => 'Hiển thị',
            'inactive' => 'Ẩn',
        ],
    ],
];