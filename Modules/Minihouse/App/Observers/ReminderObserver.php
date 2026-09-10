<?php

namespace Modules\Minihouse\App\Observers;

use Modules\Minihouse\App\Models\Reminder;

// Tự sinh nhắc việc KẾ TIẾP khi 1 nhắc việc có khai "Lặp lại mỗi X ngày" (repeat_interval_days,
// thường dùng cho "Nhắc bảo trì" định kỳ — kiểm tra PCCC, bảo trì thang máy...) được đánh dấu "Đã
// xử lý" — ngày nhắc mới = ngày nhắc CŨ + số ngày lặp lại (không phải ngày hôm nay đánh dấu xong, để
// chu kỳ luôn đều đặn dù có khi xử lý trễ vài ngày so với lịch). Nhắc việc không khai chu kỳ (loại
// "Nhắc đóng tiền"/"Nhắc hết hạn hợp đồng" thường không cần lặp) thì không sinh gì thêm, giữ đúng
// hành vi cũ.
class ReminderObserver
{
    public function updated(Reminder $reminder): void
    {
        if (! $reminder->wasChanged('is_done') || ! $reminder->is_done || ! $reminder->repeat_interval_days) {
            return;
        }

        Reminder::create([
            'title'                 => $reminder->title,
            'content'               => $reminder->content,
            'type'                  => $reminder->type,
            'repeat_interval_days'  => $reminder->repeat_interval_days,
            'room_id'               => $reminder->room_id,
            'contract_id'           => $reminder->contract_id,
            'remind_date'           => $reminder->remind_date->copy()->addDays($reminder->repeat_interval_days),
        ]);
    }
}
