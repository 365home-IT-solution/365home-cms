<?php

namespace Modules\Metering\App\Exceptions;

// Ném từ Modules\Metering\App\Models\MeteringReading::booted() ('deleting') khi cố xoá 1 log đã được
// dùng để lập hoá đơn (cùng phòng + cùng tháng đã có hoá đơn) — mirror đúng quy ước
// CannotDeletePaidInvoiceException của Invoice: chặn ở 1 điểm duy nhất (model), Filament tự bắt
// bằng cách kiểm tra lại điều kiện tương ứng ở Table để hiện thông báo thân thiện trước khi gọi
// delete(), exception này là lớp chặn cuối cho MỌI đường gọi khác (API sau này, tinker...).
class CannotDeleteUsedMeteringReadingException extends \RuntimeException
{
}
