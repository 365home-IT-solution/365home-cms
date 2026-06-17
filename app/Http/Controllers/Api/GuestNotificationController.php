<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\NotificationFcmRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GuestNotificationController extends Controller
{
    /**
     * GET /api/guest/notifications?device_token=xxx
     * Danh sách thông báo đã nhận của guest (xác định qua device_token).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'device_token' => 'required|string|max:500',
        ]);

        $token = $request->input('device_token');

        $items = NotificationFcmRecipient::with('notification')
            ->where('fcm_token', $token)
            ->whereNull('customer_id')
            ->where('status', 'sent')
            ->whereHas('notification', fn ($q) => $q->whereNotNull('sent_count'))
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $items->map(function (NotificationFcmRecipient $r) {
            return [
                'id'      => $r->notification->id,
                'title'   => $r->notification->title,
                'body'    => $r->notification->body,
                'type'    => $r->notification->type,
                'is_read' => $r->read_at !== null,
                'read_at' => $r->read_at?->toIso8601String(),
                'sent_at' => $r->created_at->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $items->currentPage(),
            'last_page'    => $items->lastPage(),
            'total'        => $items->total(),
            'unread_count' => $this->countUnread($token),
        ]);
    }

    /**
     * POST /api/guest/notifications/{id}/read
     * Đánh dấu 1 thông báo đã xem.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'device_token' => 'required|string|max:500',
        ]);

        $recipient = NotificationFcmRecipient::where('notification_fcm_id', $id)
            ->where('fcm_token', $request->input('device_token'))
            ->whereNull('customer_id')
            ->where('status', 'sent')
            ->first();

        if (! $recipient) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        return response()->json([
            'id'      => $id,
            'is_read' => true,
            'read_at' => $recipient->read_at->toIso8601String(),
        ]);
    }

    /**
     * POST /api/guest/notifications/read-all
     * Đánh dấu tất cả thông báo đã xem.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->validate([
            'device_token' => 'required|string|max:500',
        ]);

        $updated = NotificationFcmRecipient::where('fcm_token', $request->input('device_token'))
            ->whereNull('customer_id')
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "Đã đánh dấu đã xem {$updated} thông báo.",
            'updated' => $updated,
        ]);
    }

    private function countUnread(string $token): int
    {
        return NotificationFcmRecipient::where('fcm_token', $token)
            ->whereNull('customer_id')
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->count();
    }
}
