<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-envelope',
        'navigation_group' => 'Biểu mẫu',
        'navigation_label' => 'Biểu mẫu',
        'model_label' => 'Biểu mẫu',
        'plural_model_label' => 'Biểu mẫu',
    ],
    'form' => [
        'label' => [
            'form_id' => 'Chọn Biểu mẫu cấu hình',
            'to_email' => 'Mail nhận',
            'from_email' => 'Mail gửi',
            'subject' => 'Tiêu đề',
            'additional_headers' => 'Tiêu đề bổ sung',
            'message_body' => 'Nội dung email',
        ],
        'placeholder' => [
            'to_email' => 'Mail người nhận',
            'from_email' => 'Mail người gửi',
            'subject' => 'Nhập tiêu đề...',
            'additional_headers' => 'Nhập tiêu đề bổ sung...',
        ],
        'helper_text' => [
            'message_body' => 'Tên trường của bạn. Ví dụ: {{ email }} {{ tel }}. (Lưu ý: Phải có khoảng cách giữa hai dấu ngoặc nhọn). Bạn có thể đính kèm và điều chỉnh kích thước ảnh bằng cách sử dụng các nút tương ứng.',
        ],
    ],
    'table' => [
        'label' => [
            'form_name' => 'Tên biểu mẫu',
            'to_email' => 'Mail nhận',
            'from_email' => 'Mail gửi',
            'subject' => 'Tiêu đề',
            'created_at' => 'Ngày gửi',
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