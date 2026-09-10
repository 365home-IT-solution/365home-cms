<?php

namespace Modules\Minihouse\App\Support;

// Định dạng tiền VNĐ DÙNG CHUNG cho mọi Table column trong module — thay cho Filament's ->money('VND')
// (Number::currency() có thể hiện thừa ",00" tuỳ locale/ICU máy chủ, không nhất quán với cách hiển
// thị tiền thủ công đã dùng ở phần còn lại của module — Notification, ContractContentRenderer,
// FinanceReports...). blank($amount) trả về "—" thay vì "0đ" cho cột có thể chưa có giá trị.
class Money
{
    public static function format(mixed $amount): string
    {
        if (blank($amount)) {
            return '—';
        }

        return number_format((float) $amount, 0, ',', '.') . 'đ';
    }
}
