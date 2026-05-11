<?php

return [
    'page' => [
        'navigation_label' => 'BOOKING',
        'navigation_group' => 'Địa chỉ',
        'navigation_icon' => 'heroicon-o-truck',
        'title' => 'Cấu hình địa chỉ'
    ],
    'form' => [
        'section' => [
            'title' => 'Cấu hình API'
        ],
        'label' => [
            'api_token' => 'GHN API Token',
            'client_id' => 'GHN Client ID',
            'shop_id' => 'GHN Shop ID',
            'required_note' => 'Ghi chú người mua có thể yêu cầu (required_note)',
            'return_phone' => 'Số điện thoại trả hàng (return_phone)',
            'return_address' => 'Địa chỉ trả hàng (return_address)',
            'return_district_id' => 'ID quận trả về (return_district_id)',
            'return_ward_code' => 'ID đường trả về (return_ward_code)',
            'from_name' => 'Tên người gửi (from_name)',
            'from_phone' => 'Số điện thoại người gửi (from_phone)',
            'from_address' => 'Địa chỉ người gửi (from_address)',
            'from_ward_name' => 'Phường/Xã người gửi (from_ward_name)',
            'from_district_name' => 'Quận/Huyện người gửi (from_district_name)',
            'from_province_name' => 'Tỉnh/Thành phố người gửi (from_province_name)'
        ],
        'placeholder' => [
            'return_phone' => '0987654321',
            'return_address' => '39 NTT',
            'from_name' => 'Tên Shop/Công ty',
            'from_phone' => '0987654321',
            'from_address' => '456 DEF Street',
            'from_ward_name' => 'Phường 14',
            'from_district_name' => 'Quận 10',
            'from_province_name' => 'HCM'
        ],
        'helper_text' => [
            'return_district_id' => 'Không bắt buộc',
            'return_ward_code' => 'Không bắt buộc'
        ],
        'options' => [
            'required_note' => [
                'CHOTHUHANG' => 'Cho Thử Hàng',
                'CHOXEMHANGKHONGTHU' => 'Cho Xem Hàng Nhưng Không Thử',
                'KHONGCHOXEMHANG' => 'Không Cho Xem Hàng'
            ]
        ],
        'notification' => [
            'saved' => 'Đã lưu thành công'
        ]
    ]
];