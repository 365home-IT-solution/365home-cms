<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-squares-plus',
        'navigation_group' => 'Cấu hình web',
        'navigation_label' => 'Thành phần',
        'model_label' => 'Thành phần',
        'plural_model_label' => 'Thành phần',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên thành phần',
            'configurations' => 'Cấu hình',
            'config_name' => 'Tên cấu hình',
            'label' => 'Nhãn',
            'type' => 'Kiểu dữ liệu',
            'type_field' => 'Loại trường',
            'field_set' => 'Nhóm trường',
            'has_options' => 'Có tùy chọn',
            'options' => 'Tùy chọn',
            'option_label' => 'Nhãn',
            'option_value' => 'Giá trị',
        ],
        'placeholder' => [
            'name' => 'Nhập tên thành phần...',
            'config_name' => 'Nhập tên cấu hình...',
            'label' => 'Nhập nhãn...',
            'field_set' => 'Nhập nhóm trường...',
        ],
        'helper_text' => [
            'has_options' => 'Bật nếu trường này có các tùy chọn',
        ],
        'action' => [
            'add_option' => 'Thêm tùy chọn',
        ],
    ],
    'table' => [
        'label' => [
            'name' => 'Tên thành phần',
            'created_at' => 'Ngày thêm',
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