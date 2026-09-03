<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Thông báo cho admin (bảng `notifications` chuẩn của Laravel, qua trait Notifiable trên
 * App\Models\User) — mọi nguồn sự kiện đều gửi qua App\Services\AdminNotificationService::notify(),
 * hiện có các loại phân biệt bằng field 'type' (đọc từ viewData của Filament Notification, xem
 * toItem()) — FE dùng field này để chọn âm thanh thông báo phù hợp:
 *   - 'booking_confirmation' → App\Observers\OrderObserver — đơn MỚI, tức lần thanh toán đầu tiên
 *                  thành công (đủ hoặc cọc); KHÔNG dùng cho đơn vừa tạo còn 'pending' (xem
 *                  'order_pending' bên dưới).
 *   - 'order_pending' → App\Observers\OrderObserver — đơn VỪA TẠO nhưng CHƯA thanh toán ('pending')
 *                  — báo để admin theo dõi/nhắc khách, tách riêng khỏi 'booking_confirmation' vì
 *                  chưa chắc khách sẽ thanh toán.
 *   - 'payment'  → App\Observers\OrderObserver — thanh toán PHẦN CÒN LẠI của đơn deposit
 *                  (deposit → paid).
 *   - 'order_update' → App\Observers\OrderObserver — mọi lần đổi trạng thái đơn khác (hủy, hoàn
 *                  tiền...), mặc định của send() khi không thuộc 2 loại trên.
 *   - 'extra_charge' → App\Services\ExtraChargeService — phát sinh thêm trên đơn đã thanh toán
 *                  (tạo khoản phát sinh, thu tiền mặt/chuyển khoản, khách thanh toán qua PayOS,
 *                  xác nhận hoàn tiền).
 *   - 'message'  → App\Http\Controllers\Api\ChatController::send() — khách gửi tin nhắn (gắn đơn
 *                  hoặc hỗ trợ chung), kèm 'conversation_id' và 'order_code' (null nếu tin hỗ trợ
 *                  chung, không gắn đơn nào).
 *   - 'checkin'  → App\Http\Controllers\Api\UnlockController — khách mở cổng TTLock lần đầu (CHỈ
 *                  chi nhánh có đăng ký TTLock mới có loại này, chi nhánh cấp mã thủ công không tạo).
 *   - 'checkout' → cùng nơi trên, lần mở cổng thứ 2 trở đi.
 * 'order_code' xuất hiện ở hầu hết các loại trên (null với 'message' không gắn đơn) — dùng
 * order_code (không phải order_id nội bộ) vì đây là định danh FE/API bên ngoài đã dùng xuyên suốt
 * (GET /api/admin/orders/{order_code}, chat, v.v...), khỏi phải tra thêm 1 lần từ id sang code.
 * 'type' đọc thông báo cũ tạo trước khi có field này sẽ mặc định fallback về 'order' (giá trị lịch
 * sử, không còn được gán mới — FE nên coi mọi 'type' lạ như âm thanh mặc định).
 *
 * Cùng nguồn dữ liệu với chuông thông báo trên Filament panel (route web
 * GET /admin/notifications/unread-count) — 2 nơi không xung đột, chỉ là 2 cách đọc khác nhau của
 * cùng 1 bảng.
 *
 * Realtime: KHÔNG tự trả dữ liệu qua socket — mỗi khi có thông báo mới,
 * App\Services\AdminNotificationRealtimeService bắn tín hiệu 'admin_notification.new' vào phòng
 * Socket.IO 'admin:notifications' (subscribe qua sự kiện 'subscribe:admin-notifications'); client
 * nhận tín hiệu đó thì tự gọi lại GET /api/admin/notifications để làm mới danh sách. Cùng lúc đó,
 * mỗi thông báo cũng gửi kèm push FCM tới thiết bị admin (kể cả app đóng) — payload push có 'data'
 * trùng với 'type'/'order_code'/'conversation_id' ở trên để FE điều hướng khi bấm vào push.
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
        $data     = $n->data;
        $viewData = $data['viewData'] ?? [];

        return [
            'id'              => $n->id,
            // 'booking_confirmation' | 'order_pending' | 'payment' | 'order_update' |
            // 'extra_charge' | 'message' | 'checkin' | 'checkout' | string — xem
            // App\Services\AdminNotificationService. Thông báo cũ tạo trước khi có field này mặc
            // định coi là 'order' (giá trị lịch sử).
            'type'            => $viewData['type'] ?? 'order',
            'title'           => $data['title'] ?? null,
            'body'            => $data['body'] ?? null,
            'order_code'      => $viewData['order_code'] ?? null,
            'conversation_id' => $viewData['conversation_id'] ?? null,
            'is_read'         => $n->read_at !== null,
            'read_at'         => optional($n->read_at)->toIso8601String(),
            'created_at'      => $n->created_at->toIso8601String(),
        ];
    }
}
