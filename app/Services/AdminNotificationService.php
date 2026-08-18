<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

/**
 * Điểm gửi thông báo admin DUY NHẤT cho mọi nguồn sự kiện (đơn hàng, chat, check-in/check-out...) —
 * mỗi lần gọi notify() sẽ đồng thời: (1) ghi 1 row vào bảng `notifications` chuẩn của Laravel để hiện
 * trong GET /api/admin/notifications, (2) gửi push FCM tới thiết bị admin đã đăng ký (kể cả khi app
 * đóng), (3) bắn tín hiệu Socket.IO 'admin_notification.new' để admin đang mở app tự làm mới danh
 * sách ngay (xem AdminNotificationRealtimeService). Gộp 1 chỗ để mọi loại thông báo mới về sau (thêm
 * event gì) đều tự động có đủ 3 kênh trên mà không phải nhớ implement lại từng nơi.
 */
class AdminNotificationService
{
    public function __construct(
        private FcmService $fcm,
        private AdminNotificationRealtimeService $realtime,
    ) {}

    /**
     * @param  Collection<int, User>  $users
     * @param  array<string, mixed>  $data  Payload gắn kèm thông báo — LUÔN nên có 'type' (vd 'order',
     *                                      'chat', 'checkin', 'checkout') để FE phân loại/điều hướng khi
     *                                      nhận push hoặc đọc lại qua API (lưu trong viewData, xem
     *                                      Api\Admin\NotificationController::toItem()).
     */
    public function notify(
        Collection $users,
        string $title,
        string $body,
        array $data = [],
        string $icon = 'heroicon-o-bell',
        string $color = 'info',
        ?string $url = null,
    ): void {
        if ($users->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->color($color)
            ->viewData($data);

        if ($url) {
            $notification->actions([
                Action::make('view')->label('Xem')->url($url)->button(),
            ]);
        }

        $notification->sendToDatabase($users);

        try {
            $this->fcm->sendToUsers($users, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('AdminNotificationService: FCM push failed', ['error' => $e->getMessage()]);
        }

        $this->realtime->broadcastNew([
            'title' => $title,
            'body'  => $body,
            'icon'  => $icon,
            'color' => $color,
        ]);
    }

    /**
     * Danh sách user nên nhận thông báo về một đơn hàng cụ thể:
     *  - super_admin → luôn nhận tất cả
     *  - user thường → chỉ nhận nếu có quyền xem chi nhánh (category) của đơn
     *
     * Dùng chung cho mọi sự kiện gắn với đơn hàng (đơn mới/đổi trạng thái, check-in/check-out...) để
     * không lệch logic phân quyền theo chi nhánh giữa các nơi gọi.
     */
    public function recipientsForOrder(Order $order): Collection
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
}
