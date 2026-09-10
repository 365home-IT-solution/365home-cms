<?php

namespace Modules\Minihouse\App\Support;

use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Models\Tenant;

// Xác định KHÁCH THUÊ cần nhận thông báo cho 1 Reminder — DÙNG CHUNG cho mọi kênh gửi khách hàng
// (Zalo, SMS, và bất kỳ kênh nào sau này) để tránh 2 nơi tự viết lại cùng 1 logic rồi lệch nhau.
// Trước đây MinihouseZaloService tự có method private resolveRecipient()/resolveContract() riêng —
// tách ra đây khi thêm kênh SMS cần ĐÚNG logic y hệt.
//
// Nhắc đóng tiền/hết hạn hợp đồng: gửi cho khách ĐỨNG TÊN hợp đồng gắn với reminder đó. Nhắc bảo
// trì: gửi cho khách đang ở PHÒNG đó (qua hợp đồng "Đang hiệu lực"), phòng đang trống thì không có
// ai để báo — trả về null, không phải lỗi.
class ReminderRecipientResolver
{
    // withoutGlobalScopes() ở cả 2 tầng — hợp đồng/phòng có thể đã bị xoá mềm SAU KHI nhắc việc được
    // tạo (VD khách đã thanh lý/huỷ hợp đồng nhưng vẫn còn 1 nhắc đóng tiền cũ chưa xử lý) — quan hệ
    // mặc định sẽ trả null, khiến các kênh gửi khách hàng KHÔNG BAO GIỜ gửi được cho nhắc việc đó nữa
    // dù khách/phòng vẫn xác định rõ ràng được là ai (cùng lỗi lớp SoftDeletes đã gặp nhiều lần).
    public static function resolveContract(Reminder $reminder): ?Contract
    {
        if (! $reminder->contract_id) {
            return null;
        }

        return Contract::withoutGlobalScopes()
            ->with([
                'tenant' => fn ($q) => $q->withoutGlobalScopes(),
                'room'   => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->find($reminder->contract_id);
    }

    public static function resolve(Reminder $reminder): ?Tenant
    {
        if ($reminder->contract_id) {
            return self::resolveContract($reminder)?->tenant;
        }

        if ($reminder->type === Reminder::TYPE_MAINTENANCE && $reminder->room_id) {
            return $reminder->room?->contracts()
                ->where('status', Contract::STATUS_ACTIVE)
                ->latest('start_date')
                ->first()
                ?->tenant;
        }

        return null;
    }
}
