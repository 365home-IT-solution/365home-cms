<?php

namespace Modules\Minihouse\App\Exceptions;

// Ném từ các model dùng SoftDeletes (Zone/Building/Room) khi cố xoá 1 bản ghi VẪN CÒN con trực tiếp
// tham chiếu tới (Building->rooms, Zone->buildings, Room->contracts) — bắt riêng loại exception này
// ở nơi gọi (Filament/API) để hiện thông báo thân thiện thay vì để lộ trang lỗi 500. Lý do phải chặn:
// Zone/Building/Room dùng SoftDeletes nên "xoá" chỉ set deleted_at, KHÔNG kích hoạt cascade FK thật ở
// CSDL — con vẫn trỏ về 1 cha đã "biến mất" (mọi $room->building/$building->zone sau đó trả về NULL
// do SoftDeletingScope), âm thầm làm hỏng hiển thị tên toà/khu vực và ngữ cảnh lập hoá đơn cho các
// bản ghi con, thay vì báo lỗi rõ ràng ngay lúc xoá — cùng nguyên tắc với
// CannotDeletePaidInvoiceException.
class CannotDeleteReferencedRecordException extends \RuntimeException
{
}
