<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-queue-list',
        'navigation_group' => 'Biểu mẫu',
        'navigation_label' => 'Cấu hình biểu mẫu',
        'model_label' => 'Cấu hình biểu mẫu',
        'plural_model_label' => 'Cấu hình biểu mẫu',
    ],
    'form' => [
        'label' => [
            'name' => 'Tên biểu mẫu',
            'formFields' => 'Nội dung biểu mẫu',
            'type' => 'Loại trường',
            'label' => 'Tên trường',
            'slug' => 'Đường dẫn',
            'options' => 'Tùy chọn',
            'is_required' => 'Yêu cầu nhập',
            'min_length' => 'Độ dài tối thiểu',
            'max_length' => 'Độ dài tối đa',
            'submit_button_text' => 'Nội dung nút Gửi'
        ],
        'placeholder' => [
            'name' => 'Nhập tên biểu mẫu...',
            'label' => 'Nhập tiêu đề cho trường...',
            'slug' => 'Tự động tạo từ tên trường...',
            'options' => 'A|B|C'
        ],
        'helperText' => [
            'options' => 'Sử dụng cho các loại trường như select hoặc radio. Nhập các tùy chọn theo định dạng như: "A|B|C".',
            'min_length' => 'Không bắt buộc',
            'max_length' => 'Không bắt buộc'
        ],
        'options' => [
            'text' => 'Văn bản ngắn',
            'email' => 'Email',
            'textarea' => 'Văn bản dài',
            'select' => 'Select',
            'radio' => 'Tùy chọn radio',
            'tel' => 'Số điện thoại',
            'file' => 'File'
        ],
        'actions' => [
            'add_field' => 'Thêm trường mới'
        ],

        'icons' => [
            'active' => 'heroicon-o-arrow-up-on-square-stack',
            'inactive' => 'heroicon-o-archive-box'
        ],
        'colors' => [
            'active' => 'success',
            'inactive' => 'danger'
        ]
    ],
    'table' => [
        'label' => [
            'name' => 'Tên biểu mẫu',
            'created_at' => 'Ngày đăng'
        ],
        'actions' => [
            'emailSetting' => [
                'label' => 'Cấu hình Email',
                'icon' => 'heroicon-o-envelope'
            ],
            'edit' => [
                'label' => 'Chỉnh sửa'
            ],
            'delete' => [
                'label' => 'Xóa'
            ],
            'view' => [
                'label' => 'Xem'
            ]
        ],
        'bulk_actions' => [
            'delete' => [
                'label' => 'Xóa đã chọn'
            ]
        ]
    ],
    'filter' => [
        'label' => [
            'created_at' => 'Ngày tạo',
            'created_from' => 'Từ ngày',
            'created_until' => 'Đến ngày'
        ]
    ]
];

