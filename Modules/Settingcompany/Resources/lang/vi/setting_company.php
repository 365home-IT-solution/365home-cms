<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-building-office',
        'navigation_group' => 'Cấu hình thông tin',
        'navigation_label' => 'Thông tin công ty',
        'model_label' => 'Thông tin công ty',
        'plural_model_label' => 'Thông tin công ty',
    ],
    'form' => [
        'sections' => [
            'general_information' => 'Thông tin chung',
            'additional_information' => 'Thông tin bổ sung',
        ],
        'label' => [
            'name' => 'Tên doanh nghiệp',
            'address' => 'Địa chỉ',
            'phone' => 'Số điện thoại',
            'email' => 'Email',
            'website' => 'Website',
            'tax_code' => 'Mã số thuế',
            'description' => 'Mô tả',
        ],
        'placeholder' => [
            'name' => 'Nhập tên doanh nghiệp',
            'address' => 'Nhập địa chỉ doanh nghiệp',
            'phone' => 'Nhập số điện thoại liên hệ',
            'email' => 'Nhập địa chỉ email liên hệ',
            'website' => 'Nhập địa chỉ website (nếu có)',
            'tax_code' => 'Nhập mã số thuế (nếu có)',
            'description' => 'Nhập mô tả doanh nghiệp (nếu có)',
        ],
    ],
    'table' => [
        'columns' => [
            'name' => 'Tên doanh nghiệp',
            'tax_code' => 'Mã số thuế',
            'created_at' => 'Ngày tạo',
            'updated_at' => 'Ngày cập nhật',
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
