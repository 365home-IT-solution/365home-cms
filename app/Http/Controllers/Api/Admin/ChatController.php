<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatRealtimeService;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(private ChatRealtimeService $realtime) {}

    /**
     * GET /api/admin/chat
     * Danh sách tất cả conversation, sắp theo tin mới nhất.
     */
    public function index(): JsonResponse
    {
        $conversations = ChatConversation::with('customer:id,fullname,phone')
            ->orderByDesc('last_message_at')
            ->paginate(20);

        $items = collect($conversations->items())->map(fn ($conv) => [
            'id'                   => $conv->id,
            'status'               => $conv->status,
            'admin_unread'         => $conv->admin_unread,
            'last_message_preview' => $conv->last_message_preview,
            'last_message_at'      => $conv->last_message_at?->toIso8601String(),
            'customer'             => $conv->customer ? [
                'id'       => $conv->customer->id,
                'fullname' => $conv->customer->fullname,
                'phone'    => $conv->customer->phone,
            ] : null,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page'    => $conversations->lastPage(),
                'total'        => $conversations->total(),
                'per_page'     => $conversations->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/chat/{id}
     * Chi tiết conversation + tin nhắn. Tự động đánh dấu admin đã đọc.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $conv = ChatConversation::with('customer:id,fullname,phone')->find($id);

        if (! $conv) {
            return response()->json(['message' => 'Không tìm thấy cuộc trò chuyện.'], 404);
        }

        // Đánh dấu admin đã đọc
        if ($conv->admin_unread > 0) {
            $conv->admin_unread = 0;
            $conv->save();

            ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', 'customer')
                ->whereNull('read_at')
                ->update(['read_at' => Carbon::now()]);

            // Báo cho khách biết admin đã đọc
            $this->realtime->broadcastRead($conv->id, 'admin');
        }

        $beforeId = $request->query('before_id');
        $limit    = min((int) $request->query('limit', '20'), 50);

        $query = ChatMessage::where('conversation_id', $conv->id);
        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $rows     = $query->orderBy('id', 'desc')->limit($limit + 1)->get();
        $hasMore  = $rows->count() > $limit;
        $messages = $rows->take($limit)->reverse()->values()
            ->map(fn ($m) => $this->formatMessage($m, $conv->customer?->fullname));

        return response()->json([
            'conversation' => [
                'id'                   => $conv->id,
                'status'               => $conv->status,
                'admin_unread'         => 0,
                'last_message_at'      => $conv->last_message_at?->toIso8601String(),
                'last_message_preview' => $conv->last_message_preview,
                'customer'             => $conv->customer ? [
                    'id'       => $conv->customer->id,
                    'fullname' => $conv->customer->fullname,
                    'phone'    => $conv->customer->phone,
                ] : null,
            ],
            'messages'     => $messages,
            'has_more'     => $hasMore,
        ]);
    }

    /**
     * POST /api/admin/chat/{id}/messages
     * Admin gửi tin nhắn cho khách.
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        // Load customer với đầy đủ fields để gửi FCM (cần token_device)
        $conv = ChatConversation::with('customer')->find($id);
        if (! $conv) {
            return response()->json(['message' => 'Không tìm thấy cuộc trò chuyện.'], 404);
        }

        $admin = $request->user();
        $body  = trim($request->input('body'));

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type'     => 'admin',
            'sender_id'       => $admin->id,
            'body'            => $body,
        ]);

        $preview            = mb_substr($body, 0, 100);
        $newCustomerUnread  = $conv->customer_unread + 1;

        $conv->update([
            'last_message_preview' => $preview,
            'last_message_at'      => $msg->created_at,
            'customer_unread'      => $newCustomerUnread,
        ]);

        $adminName = $admin->fullname ?? $admin->email;
        $payload   = $this->formatMessage($msg, $adminName);

        // Broadcast tin nhắn vào conversation channel (khách đang mở chat sẽ nhận)
        $this->realtime->broadcastMessage($conv->id, $payload);

        // Broadcast cập nhật danh sách cho admin khác đang xem list
        $this->realtime->notifyAdminList(
            $conv->id,
            $preview,
            $msg->created_at->toIso8601String(),
            0,
            $conv->customer ? [
                'id'       => $conv->customer->id,
                'fullname' => $conv->customer->fullname,
                'phone'    => $conv->customer->phone,
            ] : []
        );

        // FCM push notification cho khách (kể cả khi app không mở)
        if ($conv->customer?->token_device) {
            try {
                app(NotificationFcmService::class)->sendToCustomer(
                    $conv->customer,
                    'Tin nhắn từ Quản trị viên',
                    $preview,
                    'chat',
                    ['conversation_id' => $conv->id]
                );
            } catch (\Throwable $e) {
                Log::warning('Chat FCM push failed', [
                    'customer_id' => $conv->customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => $payload], 201);
    }

    /**
     * POST /api/admin/chat/{id}/read
     * Đánh dấu admin đã đọc toàn bộ tin từ khách.
     */
    public function read(string $id): JsonResponse
    {
        $conv = ChatConversation::find($id);
        if (! $conv) {
            return response()->json(['message' => 'Không tìm thấy cuộc trò chuyện.'], 404);
        }

        $conv->admin_unread = 0;
        $conv->save();

        ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'customer')
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        // Báo cho khách biết admin đã đọc
        $this->realtime->broadcastRead($conv->id, 'admin');

        return response()->json(['ok' => true]);
    }

    private function formatMessage(ChatMessage $msg, ?string $senderName = null): array
    {
        return [
            'id'          => $msg->id,
            'sender_type' => $msg->sender_type,
            'sender_id'   => $msg->sender_id,
            'sender_name' => $senderName,
            'body'        => $msg->body,
            'read_at'     => $msg->read_at?->toIso8601String(),
            'created_at'  => $msg->created_at->toIso8601String(),
        ];
    }
}
