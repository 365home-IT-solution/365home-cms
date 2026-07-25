<?php

return [
    'resource' => [
        'navigation_icon' => 'heroicon-o-archive-box',
        'navigation_label' => 'BOOKING',
        'model_label' => 'BOOKING',
        'plural_model_label' => 'BOOKING',
    ],
    'form' => [
        'fieldset' => [
            'order_info' => 'Thông tin đơn hàng',
            'buyer_info' => 'Thông tin người mua',
            'payment_info' => 'Thông tin thanh toán'
        ],
        'label' => [
            'order_code' => 'Mã đơn hàng',
            'amount' => 'Tổng tiền',
            'buyer_name' => 'Tên người mua',
            'buyer_email' => 'Email người mua',
            'buyer_phone' => 'Số điện thoại người mua',
            'buyer_address' => 'Địa chỉ người mua',
            'payment_method' => 'Phương thức thanh toán',
            'shipping_method' => 'Phương thức vận chuyển',
            'is_freeship' => 'Người sẽ thanh toán phí dịch vụ',
            'status' => 'Trạng thái',
            'note_for_admin' => 'Ghi chú dành cho admin',
            'items' => 'Sản phẩm',
            'description' => 'Ghi chú dành cho đơn hàng',
            'short_description' => 'Mô tả ngắn',
            'created_at' => 'Ngày tạo',
            'updated_at' => 'Ngày cập nhật'
        ],
        'helper_text' => [
            'is_freeship' => 'Bật 🟢: Người gửi sẽ thanh toán - Tắt 🔴: Người nhận sẽ thanh toán',
            'note_for_admin' => 'Không bắt buộc'
        ],
        'options' => [
            'payment_method' => [
                'PayOS' => 'Chuyển khoản',
                'cod' => 'Cod'
            ],
            'shipping_method' => [
                'GHN' => 'GHN',
                'GHTK' => 'GHTK',
                'self' => 'Tự giao'
            ],
            'status' => [
                'pending'           => 'Đang xử lý',
                'paid'              => 'Đã thanh toán',
                'deposit'           => 'Đã đặt cọc',
                'failed'            => 'Thất bại',
                'shipped'           => 'Đã đăng đơn',
                'cancelled_payment' => 'Hủy thanh toán',
                'refunded'          => 'Hoàn tiền'
            ]
        ],
        'icons' => [
            'is_freeship' => [
                'on' => 'heroicon-m-user',
                'off' => 'heroicon-m-users'
            ]
        ],
        'colors' => [
            'is_freeship' => [
                'on' => 'success',
                'off' => 'danger'
            ]
        ]
    ],
    'table' => [
        'label' => [
            'order_code' => 'Mã đơn hàng',
            'amount' => 'Tổng tiền',
            'buyer_name' => 'Tên người mua',
            'payment_method' => 'Thanh toán',
            'status' => 'Trạng thái',
            'items' => 'Sản phẩm',
            'shipping_method' => 'Vận chuyển',
            'created_at' => 'Ngày đặt'
        ],
        'status' => [
            'pending'           => 'Đang xử lý',
            'paid'              => 'Đã thanh toán',
            'deposit'           => 'Đã đặt cọc',
            'failed'            => 'Hủy thanh toán',
            'shipped'           => 'Đã đăng đơn',
            'cancelled_payment' => 'Hủy QR',
            'refunded'          => 'Hoàn tiền'
        ],
        'shipping' => [
            'labels' => [
                'GHN' => 'GHN',
                'GHTK' => 'GHTK',
                'self' => 'Tự giao'
            ]
        ],
        'actions' => [
            'post_order' => [
                'label' => 'Đăng đơn',
                'icon' => 'heroicon-o-paper-airplane',
                'success_message' => 'Đã đăng đơn :method thành công',
                'error_message' => 'Đăng đơn thất bại: :message',
                'invalid_method' => 'Phương thức vận chuyển không hợp lệ'
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