<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Thông báo cho admin (bảng `notifications` chuẩn của Laravel, qua trait Notifiable trên
 * App\Models\User) — hiện tại nguồn duy nhất tạo ra thông báo này là App\Observers\OrderObserver
 * (đơn mới/đổi trạng thái thanh toán...), gửi qua Filament\Notifications\Notification::make()
 * ->sendToDatabase($users), nên mọi row đều có chung 'type' =
 * Filament\Notifications\DatabaseNotification — không có cột phân loại theo sự kiện, chỉ có thể
 * lọc theo đã đọc/chưa đọc.
 *
 * Cùng nguồn dữ liệu với chuông thông báo trên Filament panel (route web
 * GET /admin/notifications/unread-count) — 2 nơi không xung đột, chỉ là 2 cách đọc khác nhau của
 * cùng 1 bảng.
 *
 * Realtime: KHÔNG tự trả dữ liệu qua socket — mỗi khi có thông báo mới,
 * App\Services\AdminNotificationRealtimeService bắn tín hiệu 'admin_notification.new' vào phòng
 * Socket.IO 'admin:notifications' (subscribe qua sự kiện 'subscribe:admin-notifications'); client
 * nhận tín hiệu đó thì tự gọi lại GET /api/admin/notifications để làm mới danh sách.
 */
class NotificationController extends Controller
{
    /**
     * GET /api/admin/notifications
     * ?unread=1 lọc chỉ lấy chưa đọc, ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = $user->notifications();

        if ($request->has('unread') && $request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate($request->integer('per_page', 20));
        $notifications->getCollection()->transform(fn (DatabaseNotification $n) => $this->toItem($n));

        return response()->json([
            'data'         => $notifications->items(),
            'meta'         => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
                'per_page'     => $notifications->perPage(),
            ],
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * GET /api/admin/notifications/{id}
     * Xem chi tiết — tự đánh dấu đã đọc nếu chưa đọc (khớp hành vi chat show()).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        if (! $notification) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json(['data' => $this->toItem($notification)]);
    }

    /**
     * DELETE /api/admin/notifications/{id}
     * Xóa 1 thông báo.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $deleted = $request->user()->notifications()->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/admin/notifications
     * Xóa nhiều thông báo. Body: { "ids": ["...", "..."] }
     */
    public function destroyMany(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'string',
        ]);

        $deleted = $request->user()->notifications()
            ->whereIn('id', $request->input('ids'))
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function toItem(DatabaseNotification $n): array
    {
        $data = $n->data;

        return [
            'id'         => $n->id,
            'title'      => $data['title'] ?? null,
            'body'       => $data['body'] ?? null,
            // id đơn hàng liên quan (nếu có) — lưu trong viewData khi tạo, xem OrderObserver::send().
            'order_id'   => $data['viewData']['order_id'] ?? null,
            'is_read'    => $n->read_at !== null,
            'read_at'    => optional($n->read_at)->toIso8601String(),
            'created_at' => $n->created_at->toIso8601String(),
        ];
    }
}
