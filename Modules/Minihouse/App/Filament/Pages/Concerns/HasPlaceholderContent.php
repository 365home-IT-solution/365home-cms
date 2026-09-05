<?php

namespace Modules\Minihouse\App\Filament\Pages\Concerns;

// Khung trang tạm cho các mục menu MiniHouse CHƯA gắn dữ liệu thật — chỉ hiện đúng danh sách hạng
// mục theo tài liệu tính năng (App-quan-ly-cho-thue-theo-thang.docx), CHƯA có bảng/thao tác thêm-
// sửa-xoá. Mỗi Page dùng trait này chỉ cần khai báo lại getItems()/getPageDescription().
trait HasPlaceholderContent
{
    // Override getView() thay vì khai báo lại property $view — Filament\Pages\BasePage đã khai báo
    // sẵn "protected static string $view;" KHÔNG có giá trị mặc định; trait khai báo lại property
    // này (dù cùng tên/kiểu) bị PHP coi là "định nghĩa khác nhau, không tương thích" vì thiếu/có giá
    // trị mặc định khác nhau, gây Fatal error ngay lúc autoload.
    public function getView(): string
    {
        return 'minihouse::filament.pages.placeholder';
    }

    protected function getViewData(): array
    {
        return [
            'pageDescription' => static::getPageDescription(),
            'items'           => static::getItems(),
        ];
    }

    protected static function getPageDescription(): string
    {
        return 'Tính năng đang được xây dựng';
    }

    /** @return array<int, array{title: string, description: string}> */
    protected static function getItems(): array
    {
        return [];
    }
}
