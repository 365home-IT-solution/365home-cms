<?php

namespace Modules\Minihouse\App\Exceptions;

// Ném từ Modules\Minihouse\App\Observers\InvoiceObserver::deleting() khi cố xoá 1 hoá đơn đã có
// thanh toán được duyệt — bắt riêng loại exception này ở nơi gọi (Filament/API) để hiện thông báo
// thân thiện thay vì để lộ trang lỗi 500 chung chung.
class CannotDeletePaidInvoiceException extends \RuntimeException
{
}
