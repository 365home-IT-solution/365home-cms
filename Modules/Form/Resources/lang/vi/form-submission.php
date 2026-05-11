<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-envelope-open',
        'navigation_group' => 'Biểu mẫu',
        'navigation_label' => 'Biểu mẫu đến',
        'model_label' => 'Biểu mẫu đến',
        'plural_model_label' => 'Biểu mẫu đến',
    ],
    'form' => [
        'label' => [
            'form_field_values' => 'Chi tiết phản hồi',
            'is_viewed' => 'Đã xem',
            'additional_info' => 'Thông tin bổ sung'
        ],
        'helper_text' => [
            'is_viewed' => 'Chỉ cần kích hoạt ĐÃ XEM và LƯU. NGƯỜI XEM và THỜI GIAN sẽ tự động điền vào.'
        ],
        'key_value' => [
            'add_action_label' => 'Thêm trường',
            'key_label' => 'Tên trường',
            'value_label' => 'Giá trị'
        ]
    ],
    'table' => [
        'column' => [
            'form_name' => 'Tên Form',
            'created_at' => 'Ngày gửi',
            'is_viewed' => 'Trạng thái xem',
            'viewed_by' => 'Người xem',
            'viewed_at' => 'Thời gian xem'
        ]
    ],
    'filter' => [
        'label' => [
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày',
            'status' => 'Trạng thái',
        ],
        'options' => [
            'active' => 'Đã xem',
            'inactive' => 'Chưa xem',
        ],
    ],
];
