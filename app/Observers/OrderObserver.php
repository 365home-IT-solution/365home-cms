<?php

namespace App\Observers;

use App\Models\Customer;
use App\Models\User;
use App\Services\FcmService;
use App\Services\MembershipService;
use App\Services\NotificationFcmService;
use App\Services\OrderRealtimeService;
use App\Services\SlotRealtimeService;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Category\Entities\Category;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Payment\Entities\Order;

class OrderObserver
{
    private const TRACKED_FIELDS = [
        'status', 'amount', 'full_amount', 'deposit_percent',
        'buyer_name', 'buyer_phone', 'buyer_email',
        'payment_method', 'note_for_admin', 'guest_count',
        'checkin_date', 'checkout_date',
    ];
    /**
     * Trả về danh sách user nhận thông báo cho một đơn hàng cụ thể:
     *  - super_admin → luôn nhận tất cả
     *  - user thường → chỉ nhận nếu có quyền xem category của đơn
     */
    private function adminUsers(Order $order): \Illuminate\Support\Collection
    {
        $superAdminRole = config('filament-shield.super_admin.name');
        $superAdmins    = User::role($superAdminRole)->get();

        if ($order->category_id === null) {
            return $superAdmins->isNotEmpty() ? $superAdmins : User::all();
        }

        // Tìm parent_id của category đơn hàng để khớp với cả branch-level permission
        $matchIds = [$order->category_id];
        $cat = Category::select('id', 'parent_id')->find($order->category_id);
        if ($cat && $cat->parent_id) {
            $matchIds[] = $cat->parent_id;
        }

        // User không phải super_admin nhưng có phân quyền chi nhánh bao gồm category này
        $regionalUsers = User::whereDoesntHave('roles', fn ($q) => $q->where('name', $superAdminRole))
            ->whereHas('branchPermissions', fn ($q) => $q->whereIn('category_id', $matchIds))
            ->get();

        $all = $superAdmins->merge($regionalUsers)->unique('id');

        return $all->isNotEmpty() ? $all : User::all();
    }

    private function send(Order $order, string $title, string $icon, string $color): void
    {
        $body  = "#{$order->order_code} · {$order->buyer_name} · " . number_format($order->full_amount) . ' VNĐ';
        $users = $this->adminUsers($order);

        Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->color($color)
            ->actions([
                Action::make('view')
                    ->label('Xem đơn')
                    ->url(OrderResource::getUrl('edit', ['record' => $order->id]))
                    ->button(),
            ])
            ->sendToDatabase($users);

        // Push notification đến thiết bị di động admin (kể cả khi đóng trình duyệt)
        try {
            app(FcmService::class)->sendToUsers($users, $title, $body);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('FCM push failed: ' . $e->getMessage());
        }
    }

    private function sendToCustomer(Order $order, string $title, string $body): void
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
            'booking',
            ['order_code' => (string) $order->order_code, 'type' => 'order'],
        );
    }

    private function sendToGuest(Order $order, string $title, string $body, string $type = 'booking'): void
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
                ['order_code' => (string) $order->order_code, 'type' => 'order'],
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
     * Chỉ notify khi đơn mới tạo ở trạng thái pending
     * (status='deposit' bỏ qua — sẽ được notify bởi updated() sau khi PayOS xác nhận)
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
                new: $order->only(['order_code', 'buyer_name', 'buyer_phone', 'amount', 'status']),
                label: "#{$order->order_code} — {$order->buyer_name}",
            );
        }

        if ($order->status !== 'pending') {
            return;
        }

        $this->send($order, 'Đơn đặt phòng mới', 'heroicon-o-shopping-bag', 'info');

        $this->sendToCustomer(
            $order,
            'Đặt phòng thành công',
            "Đơn #{$order->order_code} đang chờ thanh toán. Vui lòng hoàn tất để xác nhận."
        );

        $this->sendToGuest(
            $order,
            'Đặt phòng thành công',
            $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đang chờ thanh toán."),
            'order_created'
        );
    }

    /**
     * Notify khi trạng thái đơn thay đổi, audit mọi field nhạy cảm
     */
    public function updated(Order $order): void
    {
        $changed = array_keys($order->getChanges());
        $tracked = array_intersect($changed, self::TRACKED_FIELDS);

        if (! empty($tracked)) {
            $referer = request()->headers->get('referer', '');
            if (str_contains($referer, '/admin/')) {
                AuditLogger::log(
                    action: 'update',
                    module: 'Order',
                    record: $order,
                    old: array_intersect_key($order->getOriginal(), array_flip($tracked)),
                    new: array_intersect_key($order->getChanges(), array_flip($tracked)),
                    label: "#{$order->order_code} — {$order->buyer_name}",
                );
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

        // pending → paid: đã có thông báo "Đơn đặt phòng mới", không gửi thêm
        if ($newStatus === 'paid' && $oldStatus === 'pending') {
            $this->accumulateMembershipSpending($order);
            $this->sendToCustomer(
                $order,
                'Thanh toán thành công',
                "Đơn #{$order->order_code} đã được xác nhận. Chúc bạn có trải nghiệm tốt!"
            );
            $this->sendToGuest(
                $order,
                'Thanh toán thành công',
                $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đã xác nhận.")
            );
            return;
        }

        // Phân biệt thanh toán lần 2 (deposit → paid) với thanh toán đủ ngay
        if ($newStatus === 'paid' && $oldStatus === 'deposit') {
            $this->accumulateMembershipSpending($order);
            $this->send($order, 'Đã thanh toán phần còn lại', 'heroicon-o-check-circle', 'success');
            $this->sendToCustomer(
                $order,
                'Thanh toán hoàn tất',
                "Đơn #{$order->order_code} đã được thanh toán đầy đủ."
            );
            $this->sendToGuest(
                $order,
                'Thanh toán hoàn tất',
                $this->buildGuestCheckinBody($order, "Đơn #{$order->order_code} đã thanh toán đủ.")
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
            'shipped'   => ['title' => 'Đơn đang xử lý',        'icon' => 'heroicon-o-arrow-path',   'color' => 'info'],
            'cancelled' => ['title' => 'Đơn bị hủy',            'icon' => 'heroicon-o-x-circle',     'color' => 'danger'],
            'refunded'  => ['title' => 'Đơn đã hoàn tiền',      'icon' => 'heroicon-o-arrow-uturn-left', 'color' => 'info'],
        ];

        // Slot giải phóng khi đơn bị hủy/hết hạn → broadcast để FE re-fetch
        $releasedStatuses = ['cancelled_payment', 'failed', 'cancelled'];
        if (in_array($newStatus, $releasedStatuses) && ! in_array($oldStatus, $releasedStatuses)) {
            $this->broadcastSlotRelease($order);
        }

        // Guest: thông báo khi huỷ thanh toán hoặc hết hạn QR
        if ($newStatus === 'failed' || $newStatus === 'cancelled_payment') {
            $this->sendToGuest($order, 'Thanh toán không thành công', "Đơn #{$order->order_code} đã bị huỷ. Vui lòng đặt lại nếu cần.");
        }

        if (! isset($map[$newStatus])) {
            return;
        }

        $cfg = $map[$newStatus];
        $this->send($order, $cfg['title'], $cfg['icon'], $cfg['color']);

        $customerMessages = [
            'deposit'   => ['Đơn đã cọc thành công',   "Đơn #{$order->order_code} đã nhận cọc. Vui lòng thanh toán phần còn lại khi check-in."],
            'shipped'   => ['Đơn đang được xử lý',     "Đơn #{$order->order_code} đang được xử lý bởi nhân viên."],
            'cancelled' => ['Đơn bị hủy',              "Đơn #{$order->order_code} đã bị hủy. Liên hệ hỗ trợ nếu cần thêm thông tin."],
            'refunded'  => ['Đơn đã được hoàn tiền',   "Đơn #{$order->order_code} đã được hoàn tiền. Liên hệ hỗ trợ nếu cần thêm thông tin."],
        ];

        if (isset($customerMessages[$newStatus])) {
            [$customerTitle, $customerBody] = $customerMessages[$newStatus];
            $this->sendToCustomer($order, $customerTitle, $customerBody);
            $this->sendToGuest($order, $customerTitle, $customerBody);
        }
    }

    private function broadcastSlotRelease(Order $order): void
    {
        $order->loadMissing('items');
        $service = app(SlotRealtimeService::class);

        foreach ($order->items as $item) {
            if (! $item->checkin_date || ! $item->product_id) {
                continue;
            }
            $service->broadcastReleased(
                (string) $item->product_id,
                $item->checkin_date->format('Y-m-d'),
            );
        }
    }

    public function deleted(Order $order): void
    {
        AuditLogger::log(
            action: 'delete',
            module: 'Order',
            record: $order,
            old: $order->only(['order_code', 'buyer_name', 'buyer_phone', 'amount', 'status']),
            label: "#{$order->order_code} — {$order->buyer_name}",
        );

        // Realtime: app ẩn đơn ngay khi admin xóa
        app(OrderRealtimeService::class)->broadcastOrderDeleted(
            $order->order_code,
            $order->customer_id ? (int) $order->customer_id : null,
        );

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
