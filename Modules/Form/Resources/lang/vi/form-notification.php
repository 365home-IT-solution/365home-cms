<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-bell-alert',
        'navigation_group' => 'Biểu mẫu',
        'navigation_label' => 'Thông báo và chú ý',
        'model_label' => 'Thông báo',
        'plural_model_label' => 'Thông báo và chú ý',
    ],
    'form' => [
        'label' => [
            'form_id' => 'Chọn biểu mẫu',
            'success_message' => 'Thông báo thành công',
            'error_message' => 'Thông báo lỗi',
        ],
        'default' => [
            'success_message' => 'Xin cảm ơn, thông tin của bạn đã được gửi thành công! Chúng tôi sẽ liên hệ với bạn sớm nhất!',
            'error_message' => 'Đã có lỗi xảy ra khi gửi form. Vui lòng thử lại sau.',
        ],
    ],
    'table' => [
        'label' => [
            'form_name' => 'Tên biểu mẫu thông báo',
            'success_message' => 'Thông báo thành công',
            'error_message' => 'Thông báo lỗi',
            'created_at' => 'Ngày đăng',
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
