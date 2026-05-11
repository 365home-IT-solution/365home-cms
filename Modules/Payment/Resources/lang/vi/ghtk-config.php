<?php

return [
    'page' => [
        'navigation_label' => 'Cấu hình GHTK',
        'navigation_group' => 'Cấu hình Vận chuyển',
        'navigation_icon' => 'heroicon-o-truck',
        'title' => 'Vận chuyển GHTK'
    ],
    'form' => [
        'section' => [
            'title' => 'Cấu hình GHTK'
        ],
        'label' => [
            'api_token' => 'GHTK API Token',
            'partner_code' => 'GHTK Partner Code',
            'pick_name' => 'Tên người gửi (pick_name)',
            'pick_address' => 'Địa chỉ ngắn gọn để lấy nhận hàng hóa (pick_address)',
            'pick_province' => 'Tên tỉnh/thành phố nơi lấy hàng hóa (pick_province)',
            'pick_district' => 'Tên quận/huyện nơi lấy hàng hóa (pick_district)',
            'pick_ward' => 'Tên phường/xã nơi lấy hàng hóa (pick_ward)',
            'pick_tel' => 'Số điện thoại người gửi (pick_tel)'
        ],
        'placeholder' => [
            'pick_address' => 'nhà số 5, tổ 3, ngách 11, ngõ 45',
            'pick_province' => 'TP. Hồ Chí Minh',
            'pick_district' => 'Quận 3',
            'pick_ward' => 'Phường 1'
        ],
        'notification' => [
            'saved' => 'Đã lưu thành công'
        ]
    ]
];