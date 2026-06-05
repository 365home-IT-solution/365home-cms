<?php

namespace App\Observers;

use App\Models\User;
use App\Services\FcmService;
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

        // Push notification đến thiết bị di động (kể cả khi đóng trình duyệt)
        try {
            app(FcmService::class)->sendToUsers($users, $title, $body);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('FCM push failed: ' . $e->getMessage());
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
            return;
        }

        // Phân biệt thanh toán lần 2 (deposit → paid) với thanh toán đủ ngay
        if ($newStatus === 'paid' && $oldStatus === 'deposit') {
            $this->send($order, 'Đã thanh toán phần còn lại', 'heroicon-o-check-circle', 'success');
            return;
        }

        $map = [
            'paid'      => ['title' => 'Đơn đã thanh toán đủ',  'icon' => 'heroicon-o-check-circle', 'color' => 'success'],
            'deposit'   => ['title' => 'Đơn đã đặt cọc',        'icon' => 'heroicon-o-banknotes',    'color' => 'warning'],
            'shipped'   => ['title' => 'Đơn đang xử lý',        'icon' => 'heroicon-o-arrow-path',   'color' => 'info'],
            'cancelled' => ['title' => 'Đơn bị hủy',            'icon' => 'heroicon-o-x-circle',     'color' => 'danger'],
        ];

        if (! isset($map[$newStatus])) {
            return;
        }

        $cfg = $map[$newStatus];
        $this->send($order, $cfg['title'], $cfg['icon'], $cfg['color']);
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
    }
}
