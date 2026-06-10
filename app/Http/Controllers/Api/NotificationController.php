<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationFcmRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Danh sách thông báo đã nhận của customer.
     * Query: ?since=2026-06-10T10:00:00Z  → chỉ lấy thông báo mới hơn timestamp này
     */
    public function index(Request $request): JsonResponse
    {
        $query = NotificationFcmRecipient::with('notification')
            ->where('customer_id', $request->user()->id)
            ->where('status', 'sent')
            ->whereHas('notification', fn ($q) => $q->whereNotNull('sent_count'))
            ->orderByDesc('created_at');

        if ($since = $request->query('since')) {
            $query->where('created_at', '>', $since);
        }

        $items = $query->paginate(20);

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
            'unread_count' => $this->countUnread($request->user()->id),
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     * Endpoint nhẹ cho app poll mỗi 30s để biết có thông báo mới không.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->countUnread($request->user()->id),
        ]);
    }

    private function countUnread(string $customerId): int
    {
        return NotificationFcmRecipient::where('customer_id', $customerId)
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->count();
    }

    /**
     * POST /api/notifications/{id}/read
     * Đánh dấu thông báo đã xem.
     */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $recipient = NotificationFcmRecipient::where('notification_fcm_id', $id)
            ->where('customer_id', $request->user()->id)
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
     * POST /api/notifications/read-all
     * Đánh dấu tất cả thông báo đã xem.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = NotificationFcmRecipient::where('customer_id', $request->user()->id)
            ->where('status', 'sent')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => "Đã đánh dấu đã xem {$updated} thông báo.",
            'updated' => $updated,
        ]);
    }
}
