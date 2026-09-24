<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Modules\Warehouse\App\Filament\Resources\WarehouseStockReturnResource;

// KHÔNG có nút "Tạo mới" (CreateAction) — hoàn trả CHỈ tạo qua popup "Hoàn trả" gắn thẳng ở Phiếu
// xuất kho (xem WarehouseStockOutReturnAction), vì lúc đó đã có sẵn đúng ngữ cảnh (phòng, danh sách
// dòng đã xuất) nên không cần bắt chọn tay lại từ đầu. Trang này CHỈ để XEM/QUẢN LÝ danh sách phiếu
// đã tạo (lọc, in, xoá) — sửa lại yêu cầu "dễ quản lý hơn" từ người dùng.
class ListWarehouseStockReturn extends ListRecords
{
    protected static string $resource = WarehouseStockReturnResource::class;
}
