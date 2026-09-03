<?php

namespace App\Observers;

use App\Models\Customer;
use App\Support\AuditFieldFilter;
use App\Services\AdminNotificationService;
use App\Services\MembershipService;
use App\Services\NotificationFcmService;
use App\Services\OrderRealtimeService;
use App\Services\SlotRealtimeService;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Services\CouponUsageLedger;

class OrderObserver
{
    /**
     * @param  string  $type  'booking_confirmation' (đơn mới/lần thanh toán đầu tiên) |
     *                        'payment' (thanh toán phần còn lại) | 'order_update' (mọi thay đổi
     *                        trạng thái khác — mặc định) — xem App\Services\AdminNotificationService.
     */
    private function send(Order $order, string $title, string $icon, string $color, string $type = 'order_update'): void
    {
        $body    = "#{$order->order_code} · {$order->buyer_name} · " . number_format($order->full_amount) . ' VNĐ';
        $service = app(AdminNotificationService::class);

        $service->notify(
            $service->recipientsForOrder($order),
            $title,
            $body,
            ['type' => $type, 'order_code' => $order->order_code],
            $icon,
            $color,
            OrderResource::getUrl('edit', ['record' => $order->id]),
        );
    }

    /**
     * @param  string  $type  Cùng vocabulary với App\Services\AdminNotificationService (xem
     *                        OrderObserver::send()) để FE dùng chung 1 bảng map âm thanh cho cả app
     *                        admin lẫn app khách hàng — 'order_pending' | 'booking_confirmation' |
     *                        'payment' | 'order_update' (mặc định).
     */
    private function sendToCustomer(Order $order, string $title, string $body, string $type = 'order_update'): void
    {
        if (! $order->customer_id) {
            return;
        }

        $customer = Customer::find($order->customer_id);

        if (! $customer) {
            return;
        }

        app(NotificationFcmService::class)->sendToCustomer(
            $customer,
            $title,
            $body,
            $type,
            ['order_code' => (string) $order->order_code],
        );
    }

    private function sendToGuest(Order $order, string $title, string $body, string $type = 'order_update'): void
    {
        if ($order->customer_id || empty($order->device_token)) {
            return;
        }

        try {
            app(NotificationFcmService::class)->sendToGuestToken(
                $order->device_token,
                $title,
                $body,
                $type,
                ['order_code' => (string) $order->order_code],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Guest FCM from observer failed', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ghi lại mốc thời gian CHÍNH XÁC lúc đơn chuyển sang 'paid' (thanh toán đủ, không cọc) — CHỈ
     * set 1 LẦN DUY NHẤT rồi giữ nguyên mãi mãi. Trước đây "Lịch sử thanh toán" phải dùng tạm
     * updated_at cho mốc này, nhưng cột đó bị chính Eloquent cập nhật lại ở MỌI LẦN LƯU sau đó
     * (thêm/bớt khung giờ, sửa ghi chú...), khiến mốc "Khách thanh toán đầy đủ" hiển thị SAI — trôi
     * theo lần sửa đơn gần nhất thay vì đúng lúc thanh toán thật sự. Đặt ở saving() (trước khi lưu)
     * để set trong CÙNG 1 câu UPDATE, áp dụng cho MỌI nơi set status='paid' (webhook PayOS, admin
     * xác nhận tiền mặt/chuyển khoản, API guest booking...) mà không cần sửa từng nơi gọi.
     */
    public function saving(Order $order): void
    {
        if ($order->isDirty('status') && $order->status === 'paid' && is_null($order->paid_at)) {
            $order->paid_at = now();
        }
    }

    /**
     * Thông báo admin "Đơn mới" CHỈ hiện sau khi khách đã thanh toán (đủ hoặc cọc) thành công —
     * KHÔNG báo ngay lúc tạo đơn khi còn 'pending' (chưa chắc khách sẽ thanh toán). Đơn tạo ở trạng
     * thái pending sẽ được báo sau, ở updated() khi chuyển sang paid/deposit. Trường hợp đơn được
     * tạo THẲNG ở trạng thái paid/deposit (vd webhook tạo đơn kèm xác nhận thanh toán luôn, bỏ qua
     * pending) thì báo ngay tại đây vì điều kiện "đã thanh toán" đã thỏa từ lúc tạo.
     */
    public function created(Order $order): void
    {
        // Chỉ log khi admin tạo đơn trực tiếp từ admin panel.
        // Admin panel và frontend dùng chung web guard nên phải kiểm tra qua Referer header:
        //   - Admin panel: Referer chứa '/admin/'  → log
        //   - Frontend (client hoặc admin test UI): Referer không chứa '/admin/' → bỏ qua
        //   - Webhook PayOS: auth()->user() = null → bỏ qua
        $user    = auth()->user();
        $referer = request()->headers->get('referer', '');

        if ($user && str_contains($referer, '/admin/')) {
            AuditLogger::log(
                action: 'create',
                module: 'Order',
                record: $order,
                new: AuditFieldFilter::filter($order->getAttributes()),
                label: "#{$order->order_code} — {$order->buyer_name}",
            );
        }

        $this->maybeAssignCustomerBranch($order);

        // Tín hiệu cho admin đang xem danh sách đơn/dashboard tự làm mới — riêng biệt với thông báo
        // "Đơn mới" bên dưới (thông báo CHỈ báo sau khi thanh toán, còn đây là DỮ LIỆU list nên hiện
        // ngay cả đơn pending, admin cần thấy để theo dõi).
        app(OrderRealtimeService::class)->broadcastAdminListChanged($order->order_code, 'created');

        // Phòng trường hợp đơn được tạo THẲNG ở trạng thái paid/deposit (bỏ qua pending — vd webhook
        // tạo đơn kèm xác nhận thanh toán luôn trong 1 lần insert, không qua updated() riêng) — vẫn
        // cần lưới lịch phòng phản ánh đúng ngay từ lúc tạo, không đợi lần đổi trạng thái kế tiếp.
        // Trùng với broadcastBooked() gọi trực tiếp ở luồng tạo đơn (status='pending') thì chỉ là 1
        // event thừa vô hại (cùng giá trị status), không gây sai lệch.
        $this->broadcastSlotStatusChanged($order);

        if (in_array($order->status, ['paid', 'deposit'], true)) {
            $this->send($order, 'Đơn mới', 'heroicon-o-shopping-bag', 'success', 'booking_confirmation');
            app(CouponUsageLedger::class)->confirm($order);
        }

        if ($order->status !== 'pending') {
            return;
        }

        // Đơn vừa tạo còn 'pending' (chưa thanh toán) — báo riêng cho admin theo dõi/nhắc khách,
        // TÁCH type khỏi 'booking_confirmation' (chỉ dành cho đơn ĐÃ thanh toán) để FE chọn đúng
        // mức độ ưu tiên/âm thanh khác nhau giữa "có tiền vào" và "đơn mới nhưng chưa chắc chắn".
        $this->send($order, 'Đơn mới (chờ thanh toán)', 'heroicon-o-clock', 'warning', 'order_pending');

        $this->sendToCustomer(
            $order,
            'Đặt phòng thành công',
            "Đơn #{$order->order_code} đang chờ thanh toán. Vui lòng hoàn tất để xác nhận.",
            'order_pending'
        );

        $this->sendToGuest(
            $order,
            'Đặt phòng thành công',
            $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đang chờ thanh toán."),
            'order_pending'
        );
    }

    /**
     * Notify khi trạng thái đơn thay đổi, audit mọi field nhạy cảm
     */
    public function updated(Order $order): void
    {
        // Luồng khách tự đặt qua app (BuildsRoomBooking) KHÔNG set category_id ngay lúc tạo đơn —
        // chỉ được PaymentController::handleSuccessfulPayment() backfill sau khi thanh toán thành
        // công, nên phải bắt cả ở đây (created() chỉ đủ cho đơn admin tạo trực tiếp, đã có
        // category_id ngay từ đầu — xem CreateOrder.php).
        if ($order->wasChanged('category_id')) {
            $this->maybeAssignCustomerBranch($order);
        }

        // Trước đây chỉ log khi field đổi nằm trong TRACKED_FIELDS (status/amount/buyer.../
        // checkin/checkout) — sửa note nội bộ khác, hay field mới thêm sau này không nằm trong
        // whitelist sẽ không có log. Bỏ whitelist, ghi lại toàn bộ field thay đổi.
        $changed = AuditFieldFilter::filter($order->getChanges());

        if (! empty($changed)) {
            $referer = request()->headers->get('referer', '');
            if (str_contains($referer, '/admin/')) {
                $old = array_intersect_key($order->getOriginal(), $changed);
                $new = $changed;

                // EditOrder::handleRecordUpdate() bật cờ suppress() trước khi gọi $record->update()
                // để gom dòng log này CHUNG với chi tiết phòng/khung giờ/dịch vụ (xem
                // EditOrder::afterSave()) thành 1 dòng duy nhất thay vì 2 dòng rời rạc mỗi lần Lưu.
                // Nơi khác gọi update() không bật cờ này thì vẫn ghi log ngay như cũ.
                if (\App\Support\OrderAuditBuffer::isSuppressed((string) $order->id)) {
                    \App\Support\OrderAuditBuffer::note((string) $order->id, $old, $new);
                } else {
                    AuditLogger::log(
                        action: 'update',
                        module: 'Order',
                        record: $order,
                        old: $old,
                        new: $new,
                        label: "#{$order->order_code} — {$order->buyer_name}",
                    );
                }
            }
        }

        if (! $order->wasChanged('status')) {
            return;
        }

        $newStatus = $order->status;
        $oldStatus = $order->getOriginal('status');

        if ($newStatus === $oldStatus) {
            return;
        }

        // Đồng bộ realtime cho MỌI lần đổi trạng thái, bất kể đến từ đâu (API admin, Filament,
        // webhook PayOS, cron hết hạn đơn...) — vì observer này chạy tự động theo Eloquent
        // update()/save(), không phụ thuộc code gọi có tự nhớ bắn realtime hay không (trước đây MỖI
        // nơi đổi status phải tự gọi OrderRealtimeService, dễ sót — đây là nguồn duy nhất, không sót).
        // 2 việc tách biệt: broadcastOrderUpdate() đồng bộ DỮ LIỆU cho khách đang xem đúng đơn này
        // (subscribe:order); broadcastAdminListChanged() chỉ là TÍN HIỆU làm mới cho admin đang xem
        // danh sách đơn/dashboard (không kèm dữ liệu, FE tự gọi lại REST — cùng nguyên tắc
        // admin_notification.new).
        app(OrderRealtimeService::class)->broadcastOrderUpdate(
            $order->order_code,
            ['status' => $newStatus, 'order_status' => $order->order_status],
            $order->customer_id ? (int) $order->customer_id : null,
        );
        app(OrderRealtimeService::class)->broadcastAdminListChanged($order->order_code, 'status_changed');

        // Đồng bộ field `status` trên lưới lịch phòng theo KHUNG GIỜ (GET .../rooms/{id}/time-slots,
        // xem RoomController::buildSlotStatus()) cho MỌI lần đổi trạng thái đơn — trước đây chỉ bắn
        // lúc đơn bị huỷ/hết hạn (xem broadcastSlotRelease() bên dưới), bỏ sót pending→paid,
        // pending→deposit, deposit→paid... khiến admin/khách phải tải lại trang mới thấy đúng trạng
        // thái mới nhất của từng ô ngày.
        $this->broadcastSlotStatusChanged($order);

        // Mã giảm giá CHỈ thực sự bị trừ lượt (used_count) khi đơn thanh toán thành công LẦN ĐẦU —
        // pending → paid (COD/chuyển khoản trả đủ ngay) hoặc pending → deposit (đặt cọc, đã có tiền
        // thật) — KHÔNG còn trừ ngay lúc tạo đơn nữa (xem BookingController/GuestBookingController/
        // ProductDetail/OrderController::update(), đã bỏ incrementUsage() ở đó). deposit → paid
        // (thanh toán nốt phần còn lại) KHÔNG confirm lại vì đã confirm từ lúc vào 'deposit' rồi —
        // CouponUsageLedger::confirm() cũng tự idempotent theo (order_id, code) để an toàn.
        if ($oldStatus === 'pending' && in_array($newStatus, ['paid', 'deposit'], true)) {
            app(CouponUsageLedger::class)->confirm($order);
        }

        // Đơn ĐÃ thanh toán (paid/deposit — nghĩa là mã đã được confirm ở trên hoặc lúc tạo) sau đó
        // bị hủy/hoàn tiền → hoàn lại lượt dùng mã, đối xứng với deductMembershipSpending() bên dưới.
        if (in_array($oldStatus, ['paid', 'deposit'], true)
            && in_array($newStatus, ['cancelled', 'refunded', 'failed', 'cancelled_payment'], true)) {
            app(CouponUsageLedger::class)->release($order);
        }

        // pending → paid: lần thanh toán đầu tiên (đủ ngay) — đây mới là thời điểm "Đơn mới" thực sự
        // báo cho admin (xem created()).
        if ($newStatus === 'paid' && $oldStatus === 'pending') {
            $this->accumulateMembershipSpending($order);
            $this->send($order, 'Đơn mới', 'heroicon-o-shopping-bag', 'success', 'booking_confirmation');
            $this->sendToCustomer(
                $order,
                'Thanh toán thành công',
                "Đơn #{$order->order_code} đã được xác nhận. Chúc bạn có trải nghiệm tốt!",
                'booking_confirmation'
            );
            $this->sendToGuest(
                $order,
                'Thanh toán thành công',
                $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đã xác nhận."),
                'booking_confirmation'
            );
            return;
        }

        // pending → deposit: lần thanh toán đầu tiên (cọc) — cũng tính là "Đơn mới" (đã thanh toán
        // một phần, khác với đơn pending chưa ai trả tiền).
        if ($newStatus === 'deposit' && $oldStatus === 'pending') {
            $this->send($order, 'Đơn mới', 'heroicon-o-shopping-bag', 'success', 'booking_confirmation');
            $this->sendToCustomer(
                $order,
                'Đặt cọc thành công',
                "Đơn #{$order->order_code} đã nhận cọc. Vui lòng thanh toán phần còn lại khi check-in.",
                'booking_confirmation'
            );
            $this->sendToGuest(
                $order,
                'Đặt cọc thành công',
                $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đã nhận cọc."),
                'booking_confirmation'
            );
            return;
        }

        // Phân biệt thanh toán lần 2 (deposit → paid) với thanh toán đủ ngay
        if ($newStatus === 'paid' && $oldStatus === 'deposit') {
            $this->accumulateMembershipSpending($order);
            $this->send($order, 'Đã thanh toán phần còn lại', 'heroicon-o-check-circle', 'success', 'payment');
            $this->sendToCustomer(
                $order,
                'Thanh toán hoàn tất',
                "Đơn #{$order->order_code} đã được thanh toán đầy đủ.",
                'payment'
            );
            $this->sendToGuest(
                $order,
                'Thanh toán hoàn tất',
                $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đã thanh toán đủ."),
                'payment'
            );
            return;
        }

        // paid → bất kỳ trạng thái nào khác: hoàn trả điểm
        if ($oldStatus === 'paid' && $newStatus !== 'paid') {
            $this->deductMembershipSpending($order);
        }

        // Mọi transition khác → paid (failed/cancelled/... → paid): tích điểm
        if ($newStatus === 'paid') {
            $this->accumulateMembershipSpending($order);
        }

        $map = [
            'paid'      => ['title' => 'Đơn đã thanh toán đủ',  'icon' => 'heroicon-o-check-circle', 'color' => 'success'],
            'deposit'   => ['title' => 'Đơn đã đặt cọc',        'icon' => 'heroicon-o-banknotes',    'color' => 'warning'],
            'cancelled' => ['title' => 'Đơn bị hủy',            'icon' => 'heroicon-o-x-circle',     'color' => 'danger'],
            'refunded'  => ['title' => 'Đơn đã hoàn tiền',      'icon' => 'heroicon-o-arrow-uturn-left', 'color' => 'info'],
        ];

        // Slot giải phóng khi đơn bị hủy/hết hạn/hoàn tiền → broadcast để FE re-fetch
        $releasedStatuses = ['cancelled_payment', 'failed', 'cancelled', 'refunded'];
        if (in_array($newStatus, $releasedStatuses) && ! in_array($oldStatus, $releasedStatuses)) {
            $this->broadcastSlotRelease($order);
        }

        // Thông báo khi huỷ thanh toán hoặc hết hạn QR (kể cả cron ExpirePaymentOrders tự huỷ đơn
        // pending quá 15 phút) — trước đây CHỈ báo guest (device_token), khách đã đăng nhập
        // (customer_id) không nhận được gì khi đơn tự hết hạn.
        if ($newStatus === 'failed' || $newStatus === 'cancelled_payment') {
            $this->sendToCustomer($order, 'Thanh toán không thành công', "Đơn #{$order->order_code} đã bị huỷ. Vui lòng đặt lại nếu cần.");
            $this->sendToGuest($order, 'Thanh toán không thành công', "Đơn #{$order->order_code} đã bị huỷ. Vui lòng đặt lại nếu cần.");
        }

        if (! isset($map[$newStatus])) {
            return;
        }

        $cfg = $map[$newStatus];
        $this->send($order, $cfg['title'], $cfg['icon'], $cfg['color']);

        $customerMessages = [
            'deposit'   => ['Đơn đã cọc thành công',   "Đơn #{$order->order_code} đã nhận cọc. Vui lòng thanh toán phần còn lại khi check-in."],
            'cancelled' => ['Đơn bị hủy',              "Đơn #{$order->order_code} đã bị hủy. Liên hệ hỗ trợ nếu cần thêm thông tin."],
            'refunded'  => ['Đơn đã được hoàn tiền',   "Đơn #{$order->order_code} đã được hoàn tiền. Liên hệ hỗ trợ nếu cần thêm thông tin."],
        ];

        if (isset($customerMessages[$newStatus])) {
            [$customerTitle, $customerBody] = $customerMessages[$newStatus];
            $this->sendToCustomer($order, $customerTitle, $customerBody);
            $this->sendToGuest($order, $customerTitle, $customerBody);
        }
    }

    /**
     * CHỈ còn xử lý phòng theo NGÀY (styles=2, kênh 'daily.*' riêng) — phòng theo KHUNG GIỜ
     * (styles=1) đã được broadcastSlotStatusChanged() xử lý cho MỌI transition (gồm cả huỷ/hết
     * hạn), gọi thêm ở đây sẽ bắn trùng 2 event cho cùng 1 ô.
     */
    private function broadcastSlotRelease(Order $order): void
    {
        $order->loadMissing('items.product');
        $service = app(SlotRealtimeService::class);

        foreach ($order->items as $item) {
            if (! $item->checkin_date || ! $item->product_id) {
                continue;
            }
            if ((int) ($item->product->styles ?? 1) !== 2) {
                continue;
            }
            $service->broadcastReleased(
                (string) $item->product_id,
                $item->checkin_date->format('Y-m-d'),
            );
        }
    }

    /**
     * Phản ánh đúng field `status` mà GET /api/admin/rooms/{id}/time-slots trả cho mỗi ô khung giờ
     * x ngày (xem RoomController::buildSlotStatus()) — CHỈ pending/paid coi là chiếm chỗ, mọi trạng
     * thái khác (kể cả deposit, theo đúng quyết định nghiệp vụ ở đó — đặt cọc KHÔNG hiện là đã đặt)
     * hiển thị 'available'. Bắn cho MỌI lần đổi trạng thái đơn (gọi từ updated(), đã đảm bảo status
     * thực sự thay đổi). CHỈ áp dụng phòng theo KHUNG GIỜ (styles=1 hoặc null) — phòng theo NGÀY
     * (styles=2) dùng kênh 'daily.*' riêng, xem broadcastSlotRelease().
     */
    private function broadcastSlotStatusChanged(Order $order): void
    {
        $order->loadMissing([
            'items.product.roomTimeSlots' => fn ($q) => $q->whereNull('date'),
            'items.product.roomTimeSlots.timeSlot',
        ]);

        $service = app(SlotRealtimeService::class);
        $status  = in_array($order->status, ['pending', 'paid'], true) ? $order->status : 'available';

        $order->items
            ->filter(fn ($item) => $item->checkin_date && $item->product && (int) ($item->product->styles ?? 1) !== 2)
            ->groupBy(fn ($item) => $item->product_id . '|' . $item->checkin_date->format('Y-m-d'))
            ->each(function ($items) use ($service, $status) {
                $first   = $items->first();
                $slotIds = $items
                    ->map(fn ($item) => $this->resolveItemTimeslotId($item))
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                $service->broadcastStatusChanged(
                    (string) $first->product_id,
                    $first->checkin_date->format('Y-m-d'),
                    $slotIds,
                    $status,
                );
            });
    }

    /**
     * Suy timeslot_id của 1 order_item (đơn theo giờ) — order_items không lưu timeslot_id trực
     * tiếp, khớp ngược qua giờ bắt đầu (checkin_date, HH:mm:ss) với RoomTimeSlot lặp lại (date IS
     * NULL) của phòng — CÙNG cách làm với Api\Admin\OrderController::resolveItemTimeslotId() (tách
     * riêng bản sao ở đây vì đó là method private của Controller, Observer không gọi được).
     */
    private function resolveItemTimeslotId($item): ?int
    {
        $startTime = \Carbon\Carbon::parse($item->checkin_date)->format('H:i:s');
        $rts = $item->product->roomTimeSlots
            ->first(fn ($rts) => $rts->timeSlot && $rts->timeSlot->start_time === $startTime);

        return $rts?->timeslot_id;
    }

    /**
     * Hoàn lượt dùng mã giảm giá TRƯỚC khi đơn thật sự bị xóa — coupon_usages/coupon_usage_logs có
     * cascadeOnDelete theo order_id, nên nếu chờ tới deleted() (chạy SAU khi DB đã cascade xóa) thì
     * CouponUsageLedger::release() sẽ không tìm thấy dòng coupon_usages nào để hoàn used_count nữa.
     */
    public function deleting(Order $order): void
    {
        if (in_array($order->status, ['paid', 'deposit'], true)) {
            app(CouponUsageLedger::class)->release($order);
        }
    }

    public function deleted(Order $order): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Order',
            record: $order,
            old: AuditFieldFilter::filter($order->getAttributes()),
            label: "#{$order->order_code} — {$order->buyer_name}",
        );

        // Realtime: app ẩn đơn ngay khi admin xóa — chỉ bắn khi có order_code (1 số đơn cũ/lỗi dữ
        // liệu có order_code null; broadcastOrderDeleted() ép kiểu string không nullable nên gọi
        // với null sẽ ném TypeError, làm toàn bộ luồng xoá đơn thất bại dù items/order đã xoá xong
        // — phát hiện được khi test luồng xoá đơn, không liên quan broadcast khung giờ realtime).
        if ($order->order_code) {
            app(OrderRealtimeService::class)->broadcastOrderDeleted(
                $order->order_code,
                $order->customer_id ? (int) $order->customer_id : null,
            );
            app(OrderRealtimeService::class)->broadcastAdminListChanged($order->order_code, 'deleted');
        }

        // Trừ lại chi tiêu khi xóa đơn đã thanh toán
        if ($order->status === 'paid' && $order->customer_id && ! $order->exclude_from_stats) {
            $customer = Customer::find($order->customer_id);
            if ($customer) {
                $amount = (float) ($order->full_amount ?? $order->amount ?? 0);
                if ($amount > 0) {
                    try {
                        $newSpending = max(0, (float) $customer->total_spending - $amount);
                        $customer->update(['total_spending' => $newSpending]);
                        $customer->refresh();
                        app(MembershipService::class)->recalculateTier($customer);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('MembershipService: deduct spending on delete failed: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    private function buildGuestCheckinBody(Order $order, string $prefix): string
    {
        $order->loadMissing('items');

        $checkin  = $order->items->min('checkin_date');
        $checkout = $order->items->max('checkout_date');

        $parts = [$prefix];

        if ($checkin) {
            $parts[] = 'Check-in: ' . \Carbon\Carbon::parse($checkin)->format('H:i d/m');
        }
        if ($checkout) {
            $parts[] = 'Check-out: ' . \Carbon\Carbon::parse($checkout)->format('H:i d/m');
        }

        return implode(' ', $parts);
    }

    /**
     * Khách vãng lai tự đăng ký thành viên qua app (ZaloOtpController::register()) không được gán
     * customer_categories nào lúc tạo tài khoản — chỉ CustomerController::store() (admin/nhân viên
     * tạo hộ) mới tự gán chi nhánh gốc của người tạo. Bù lại: đơn ĐẦU TIÊN của khách hàng có
     * category_id (chi nhánh gốc, xem CreateOrder.php) sẽ tự động gán làm customer_categories, để
     * khách tự đăng ký cũng thuộc về đúng chi nhánh họ đặt phòng đầu tiên — chỉ áp dụng khi khách
     * CHƯA có chi nhánh nào (tránh ghi đè chi nhánh đã gán trước đó, kể cả trường hợp không phải
     * đơn đầu tiên thực sự nhưng category_id của các đơn trước null).
     */
    private function maybeAssignCustomerBranch(Order $order): void
    {
        if (! $order->customer_id || ! $order->category_id) {
            return;
        }

        $customer = Customer::find($order->customer_id);
        if (! $customer || $customer->categories()->exists()) {
            return;
        }

        $customer->categories()->sync([$order->category_id]);
    }

    private function accumulateMembershipSpending(Order $order): void
    {
        // Chỉ tích điểm khi đơn đã thanh toán thành công
        if ($order->status !== 'paid') {
            return;
        }

        if (! $order->customer_id || $order->exclude_from_stats) {
            return;
        }

        $customer = Customer::find($order->customer_id);
        if (! $customer) {
            return;
        }

        $amount = (float) ($order->full_amount ?? $order->amount ?? 0);
        if ($amount <= 0) {
            return;
        }

        try {
            app(MembershipService::class)->addSpending($customer, $amount);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MembershipService::addSpending failed: ' . $e->getMessage());
        }
    }

    private function deductMembershipSpending(Order $order): void
    {
        if (! $order->customer_id || $order->exclude_from_stats) {
            return;
        }

        $customer = Customer::find($order->customer_id);
        if (! $customer) {
            return;
        }

        $amount = (float) ($order->full_amount ?? $order->amount ?? 0);
        if ($amount <= 0) {
            return;
        }

        try {
            $newSpending = max(0, (float) $customer->total_spending - $amount);
            $customer->update(['total_spending' => $newSpending]);
            $customer->refresh();
            app(MembershipService::class)->recalculateTier($customer);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MembershipService: deductSpending failed: ' . $e->getMessage());
        }
    }
}
