<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Services\ChatRealtimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Payment\Entities\Order;

class ChatController extends Controller
{
    public function __construct(private ChatRealtimeService $realtime) {}

    /**
     * GET /api/chat
     * Lấy hoặc tạo conversation + 20 tin nhắn gần nhất. Tự động đánh dấu đã đọc.
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user();

        $conv = ChatConversation::firstOrCreate(
            ['customer_id' => $customer->id],
            ['status' => 'open']
        );

        // Đánh dấu khách đã đọc tất cả tin từ admin
        if ($conv->customer_unread > 0) {
            $conv->customer_unread = 0;
            $conv->save();

            ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', 'admin')
                ->whereNull('read_at')
                ->update(['read_at' => Carbon::now()]);

            $this->realtime->broadcastRead($conv->id, 'customer');
        }

        $total    = ChatMessage::where('conversation_id', $conv->id)->count();
        $messages = ChatMessage::where('conversation_id', $conv->id)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'conversation' => [
                'id'              => $conv->id,
                'status'          => $conv->status,
                'customer_unread' => 0,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
            ],
            'messages'     => $messages->map(fn ($m) => $this->formatMessage($m)),
            'has_more'     => $total > 20,
        ]);
    }

    /**
     * GET /api/chat/order/{order_code}
     * Lấy conversation theo đơn hàng + 20 tin nhắn gần nhất.
     * Tự động gắn order_id vào conversation và đánh dấu đã đọc.
     */
    public function showByOrder(Request $request, string $orderCode): JsonResponse
    {
        $customer = $request->user();

        $order = Order::where('order_code', $orderCode)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order) {
            return response()->json(['message' => 'Đơn hàng không tồn tại.'], 404);
        }

        $conv = ChatConversation::firstOrCreate(
            ['customer_id' => $customer->id],
            ['status' => 'open', 'order_id' => $order->id]
        );

        // Gắn đơn vào conversation nếu chưa có hoặc khác đơn cũ
        if ((int) $conv->order_id !== $order->id) {
            $conv->order_id = $order->id;
            $conv->save();
        }

        // Đánh dấu khách đã đọc
        if ($conv->customer_unread > 0) {
            $conv->customer_unread = 0;
            $conv->save();

            ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', 'admin')
                ->whereNull('read_at')
                ->update(['read_at' => Carbon::now()]);

            $this->realtime->broadcastRead($conv->id, 'customer');
        }

        $baseQuery = ChatMessage::where('conversation_id', $conv->id)
            ->where('order_id', $order->id);

        $total    = (clone $baseQuery)->count();
        $messages = (clone $baseQuery)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        return response()->json([
            'conversation' => [
                'id'              => $conv->id,
                'status'          => $conv->status,
                'customer_unread' => 0,
                'last_message_at' => $conv->last_message_at?->toIso8601String(),
                'order_code'      => $order->order_code,
            ],
            'messages' => $messages->map(fn ($m) => $this->formatMessage($m)),
            'has_more' => $total > 20,
        ]);
    }

    /**
     * GET /api/chat/messages?before_id=&limit=
     * Tải thêm tin nhắn cũ hơn (cuộn lên).
     */
    public function messages(Request $request): JsonResponse
    {
        $customer = $request->user();
        $conv     = ChatConversation::where('customer_id', $customer->id)->first();

        if (! $conv) {
            return response()->json(['messages' => [], 'has_more' => false]);
        }

        $limit    = min((int) $request->query('limit', 20), 50);
        $beforeId = $request->query('before_id');

        $query = ChatMessage::where('conversation_id', $conv->id);

        // Lọc theo đơn nếu có order_code (dùng khi phân trang trong context đơn)
        if ($orderCode = $request->query('order_code')) {
            $order = Order::where('order_code', $orderCode)
                ->where('customer_id', $customer->id)
                ->first();
            if ($order) {
                $query->where('order_id', $order->id);
            }
        }

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $rows    = $query->orderBy('id', 'desc')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return response()->json([
            'messages' => $rows->take($limit)->reverse()->values()->map(fn ($m) => $this->formatMessage($m)),
            'has_more' => $hasMore,
        ]);
    }

    /**
     * POST /api/chat/messages
     * Khách gửi tin nhắn.
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'body'       => 'required|string|max:2000',
            'order_code' => 'nullable|string',
        ]);

        $customer = $request->user();
        $body     = trim($request->input('body'));

        $conv = ChatConversation::firstOrCreate(
            ['customer_id' => $customer->id],
            ['status' => 'open']
        );

        // Gắn đơn hàng vào conversation và tag tin nhắn nếu có order_code
        $messageOrderId = null;
        if ($orderCode = $request->input('order_code')) {
            $order = Order::where('order_code', $orderCode)
                ->where('customer_id', $customer->id)
                ->first();
            if ($order) {
                $messageOrderId = $order->id;
                if ((int) $conv->order_id !== $order->id) {
                    $conv->order_id = $order->id;
                }
            }
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'order_id'        => $messageOrderId,
            'sender_type'     => 'customer',
            'sender_id'       => $customer->id,
            'body'            => $body,
        ]);

        $newAdminUnread = $conv->admin_unread + 1;
        $preview        = mb_substr($body, 0, 100);

        $conv->update([
            'last_message_preview' => $preview,
            'last_message_at'      => $msg->created_at,
            'admin_unread'         => $newAdminUnread,
        ]);

        $payload = $this->formatMessage($msg, $customer->fullname);

        // Broadcast tin nhắn vào conversation channel (admin đang xem conv sẽ nhận)
        $this->realtime->broadcastMessage($conv->id, $payload);

        // Broadcast cập nhật danh sách cho admin (admin đang xem list sẽ nhận)
        $this->realtime->notifyAdminList(
            $conv->id,
            $preview,
            $msg->created_at->toIso8601String(),
            $newAdminUnread,
            ['id' => $customer->id, 'fullname' => $customer->fullname, 'phone' => $customer->phone]
        );

        return response()->json(['message' => $payload], 201);
    }

    /**
     * POST /api/chat/read
     * Đánh dấu tất cả tin nhắn từ admin là đã đọc.
     */
    public function read(Request $request): JsonResponse
    {
        $customer = $request->user();
        $conv     = ChatConversation::where('customer_id', $customer->id)->first();

        if (! $conv) {
            return response()->json(['ok' => true]);
        }

        $conv->customer_unread = 0;
        $conv->save();

        ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        // Báo cho admin biết khách đã đọc
        $this->realtime->broadcastRead($conv->id, 'customer');

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
