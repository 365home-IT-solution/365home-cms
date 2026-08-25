<?php

return [
    'empty' => [
        'title' => "Không tìm thấy thư viện hoặc thư mục nào",
    ],
    'folders' => [
        'title' => 'Thư viện',
        'single' => 'Thư mục',
        'columns' => [
            'name' => 'Tên',
            'collection' => 'Bộ sưu tập',
            'description' => 'Mô tả',
            'is_public' => 'Is Public',
            'has_user_access' => 'Có quyền truy cập của người dùng',
            'users' => 'Người dùng',
            'icon' => 'Icon',
            'color' => 'Color',
            'is_protected' => 'Được bảo vệ',
            'password' => 'Mật khẩu',
            'password_confirmation' => 'Xác nhận mật khẩu',
        ],
        'notifications' => [
            'create-subfolder' => 'Thư mục con được tạo thành công',
        ],
        'group' => 'Cấu hình web',
    ],
    'media' => [
        'title' => 'Thư viện',
        'single' => 'Thư viện',
        'columns' => [
            'image' => 'Hình ảnh',
            'model' => 'Model',
            'collection_name' => 'Tên bộ sưu tập',
            'size' => 'Size',
            'order_column' => 'Thứ tự cột',
        ],
        'actions' => [
            'sub_folder'=> [
              'label' => "Tạo thư mục con"
            ],
            'create' => [
                'label' => 'Thêm thư viện',
                'form' => [
                    'file' => 'File',
                    'title' => 'Tiêu đề',
                    'description' => 'Mô tả',
                ],
            ],
            'delete' => [
                'label' => 'Xóa thư mục',
            ],
            'edit' => [
                'label' => 'Sửa thư mục',
            ],
        ],
        'notifications' => [
            'create-media' => 'Thư viện được tạo thành công',
            'delete-folder' => 'Đã xóa thư mục thành công',
            'delete-media' => 'Đã xóa file thành công',
            'edit-folder' => 'Đã chỉnh sửa thư mục thành công',
            'error' =>[
               'title' => 'Không thể xóa file!' ,
               'body' => 'File hoặc thư mục bạn muốn xóa có thể không tồn tại hoặc đã bị xóa trước đó.'
            ]
        ],
        'meta' => [
            'model' => 'Kiểu dữ liệu',
            'file-name' => 'Tên tệp',
            'type' => 'Loại',
            'size' => 'Kích thước',
            'disk' => 'Kho lưu trữ',
            'url' => 'URL',
            'delete-media' => 'Xóa thư viện',
        ],
    ],
];
