<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-credit-card',
        'navigation_group' => 'Cấu hình web',
        'navigation_label' => 'Thanh toán online',
        'model_label' => 'Thanh toán online',
        'plural_model_label' => 'Thanh toán online',
    ],
    'form' => [
        'section' => [
            'title' => 'Thông tin cấu hình PayOS đã liên kết'
        ],
        'label' => [
            'client_id' => 'PAYOS Client ID',
            'api_key' => 'PAYOS API Key',
            'checksum_key' => 'PAYOS Checksum Key'
        ],
        'prefix_icon' => [
            'client_id' => 'heroicon-o-user-circle',
            'api_key' => 'heroicon-o-key',
            'checksum_key' => 'heroicon-o-shield-check'
        ]
    ],
    'table' => [
        'label' => [
            'id' => 'ID',
            'client_id' => 'PAYOS Client ID',
            'api_key' => 'PAYOS API Key',
            'checksum_key' => 'PAYOS Checksum Key'
        ],
        'actions' => [
            'edit' => [
                'label' => 'Chỉnh sửa'
            ],
            'delete' => [
                'label' => 'Xóa'
            ],
            'view' => [
                'label' => 'Xem chi tiết'
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