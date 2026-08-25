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
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

class ChatController extends Controller
{
    public function __construct(private ChatRealtimeService $realtime) {}

    /**
     * GET /api/admin/chat
     * Danh sách conversation, sắp theo tin mới nhất — mặc định CHỈ lấy hội thoại có ít nhất 1 đơn
     * (bất kỳ order thread nào trong hội thoại, xem quan hệ ChatConversation::messages()→order())
     * thuộc chi nhánh mà admin đang đăng nhập được phép xem (User::allowedCategoryIds() — chi
     * nhánh CHA user được gán + toàn bộ chi nhánh CON, đệ quy). Super_admin hoặc admin không bị
     * gán quyền chi nhánh cụ thể (allowedCategoryIds() rỗng) thì thấy TẤT CẢ, không giới hạn.
     *
     * Query param tuỳ chọn 'categories': danh sách SLUG chi nhánh (bảng categories.slug), CHỌN
     * NHIỀU bằng dấu phẩy (vd "89-xuan-thuy-an-binh-can-tho,254-xuan-thuy-an-binh-can-tho") để LỌC
     * THÊM về đúng 1 hoặc nhiều chi nhánh cụ thể — vẫn phải nằm trong phạm vi quyền ở trên (nếu
     * admin bị giới hạn quyền, gửi slug ngoài phạm vi sẽ bị bỏ qua, không lộ dữ liệu chi nhánh
     * khác). Slug không khớp category nào thì bị bỏ qua luôn (không báo lỗi — coi như không lọc
     * theo slug đó).
     */
    public function index(Request $request): JsonResponse
    {
        $categoryIds = $this->resolveCategoryFilter($request);

        $query = ChatConversation::with('customer:id,fullname,phone')
            ->orderByDesc('last_message_at');

        if ($categoryIds !== null) {
            if (empty($categoryIds)) {
                // Phạm vi quyền/lọc thu hẹp về rỗng (vd categories gửi lên không nằm trong quyền
                // admin) → không thấy hội thoại nào, KHÁC với null (không giới hạn gì).
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas(
                    'messages',
                    fn ($q) => $q->whereHas('order', fn ($oq) => $oq->whereIn('category_id', $categoryIds))
                );
            }
        }

        $conversations = $query->paginate(20);

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
     * Tính danh sách category_id cuối cùng dùng để lọc index() — null nghĩa là "không lọc gì cả"
     * (khác [] = "lọc về không có gì"). Xem docblock index() để biết ngữ nghĩa 'categories' và
     * phạm vi quyền theo chi nhánh.
     *
     * @return array<int>|null
     */
    private function resolveCategoryFilter(Request $request): ?array
    {
        $requested = null;

        if ($request->filled('categories')) {
            $slugs = collect(explode(',', (string) $request->query('categories')))
                ->map(fn ($v) => trim($v))
                ->filter()
                ->unique()
                ->values();

            $requested = Category::whereIn('slug', $slugs)->pluck('id')->all();
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return $requested;
        }

        // [] = admin không bị gán quyền chi nhánh cụ thể → không giới hạn (giống quy ước dùng ở
        // CouponResource::getEloquentQuery()/MembershipTierResource...).
        $allowed = $user->allowedCategoryIds();

        if ($requested !== null) {
            return empty($allowed) ? $requested : array_values(array_intersect($requested, $allowed));
        }

        return empty($allowed) ? null : $allowed;
    }

    /**
     * GET /api/admin/chat/{id}
     * Chi tiết conversation + tin nhắn. Tự động đánh dấu admin đã đọc.
     *
     * Query param tuỳ chọn 'order_code': lọc chỉ lấy tin nhắn của ĐÚNG 1 đơn (khớp cách khách hàng
     * nhắn "hỗ trợ ở đơn" — xem ChatController::showByOrder() phía client). Không truyền = giữ
     * nguyên hành vi cũ (lấy TẤT CẢ tin nhắn của khách, mọi đơn trộn chung) để không phá caller cũ.
     * Dù lọc hay không, mỗi tin nhắn trả về đều kèm sẵn order_code/room_name (xem formatMessage())
     * để admin luôn biết tin đó đang nói về đơn nào mà không cần tra cứu thêm.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $conv = ChatConversation::with(['customer:id,fullname,phone', 'order.items.product', 'order.services'])->find($id);

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

        $beforeId   = $request->query('before_id');
        $limit      = min((int) $request->query('limit', '20'), 50);
        $orderCode  = $request->query('order_code');
        $scopeOrder = null;

        $query = ChatMessage::where('conversation_id', $conv->id);

        if ($orderCode) {
            $scopeOrder = Order::where('order_code', $orderCode)
                ->where('customer_id', $conv->customer_id)
                ->first();

            if (! $scopeOrder) {
                return response()->json(['message' => 'Không tìm thấy đơn hàng này của khách.'], 404);
            }

            $query->where('order_id', $scopeOrder->id);
        }

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        $rows        = $query->orderBy('id', 'desc')->limit($limit + 1)->get();
        $hasMore     = $rows->count() > $limit;
        $pageRows    = $rows->take($limit)->reverse()->values();
        $orderLookup = $this->orderLookupFor($pageRows);
        $messages    = $pageRows->map(fn ($m) => $this->formatMessage($m, $conv->customer?->fullname, $orderLookup));

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
            // 'order': chi tiết đầy đủ đơn đang xem — nếu có lọc order_code thì ưu tiên đơn đó,
            // không thì fallback về đơn "đang trỏ tới" của conversation (giữ hành vi cũ).
            'order'        => $scopeOrder
                ? $this->buildOrderInfo($scopeOrder)
                : ($conv->order ? $this->buildOrderInfo($conv->order) : null),
        ]);
    }

    /**
     * GET /api/admin/chat/{id}/orders
     * Danh sách các đơn mà khách đã nhắn tin hỗ trợ trong cuộc trò chuyện này — mỗi đơn 1 "khung
     * chat" riêng (tin nhắn có order_id trỏ tới đơn đó). Dùng để admin biết khách đã hỏi về những
     * đơn nào, rồi gọi GET .../{id}?order_code=... để xem đúng khung chat của đơn đó.
     */
    public function orders(string $id): JsonResponse
    {
        $conv = ChatConversation::find($id);

        if (! $conv) {
            return response()->json(['message' => 'Không tìm thấy cuộc trò chuyện.'], 404);
        }

        $threads = ChatMessage::where('conversation_id', $conv->id)
            ->whereNotNull('order_id')
            ->selectRaw('order_id, COUNT(*) as message_count, MAX(created_at) as last_message_at')
            ->groupBy('order_id')
            ->orderByDesc('last_message_at')
            ->get();

        $orders = Order::whereIn('id', $threads->pluck('order_id'))
            ->with('items.product:id,name')
            ->get()
            ->keyBy('id');

        $data = $threads->map(function ($t) use ($orders) {
            $order   = $orders->get($t->order_id);
            $product = $order?->items->first()?->product;

            return [
                'order_id'        => $t->order_id,
                'order_code'      => $order?->order_code,
                'room_name'       => $product?->name,
                'message_count'   => (int) $t->message_count,
                'last_message_at' => $t->last_message_at,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/admin/chat/{id}/messages
     * Admin gửi tin nhắn cho khách.
     *
     * Body tuỳ chọn 'order_code': chọn đúng khung chat của 1 đơn để trả lời (khớp cách khách hàng
     * gửi 'order_code' khi nhắn — xem ChatController::send() phía client). Không truyền = giữ
     * nguyên hành vi cũ (gắn theo order_id đang "trỏ tới" hiện tại của conversation).
     */
    public function send(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'body'       => 'required|string|max:2000',
            'order_code' => 'nullable|string',
        ]);

        // Load customer với đầy đủ fields để gửi FCM (cần token_device)
        $conv = ChatConversation::with('customer')->find($id);
        if (! $conv) {
            return response()->json(['message' => 'Không tìm thấy cuộc trò chuyện.'], 404);
        }

        $admin      = $request->user();
        $body       = trim($request->input('body'));
        $messageOrderId = $conv->order_id;

        if ($request->filled('order_code')) {
            $order = Order::where('order_code', $request->input('order_code'))
                ->where('customer_id', $conv->customer_id)
                ->first();

            if (! $order) {
                return response()->json(['message' => 'Không tìm thấy đơn hàng này của khách.'], 404);
            }

            $messageOrderId = $order->id;
            // Cùng semantics với client: order_id trên conversation là con trỏ "đơn đang thao tác
            // gần nhất", KHÔNG phải phạm vi lọc — xem ChatController::showByOrder() phía client.
            $conv->order_id = $order->id;
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'order_id'        => $messageOrderId,
            'sender_type'     => 'admin',
            'sender_id'       => $admin->id,
            'body'            => $body,
        ]);

        $preview            = mb_substr($body, 0, 100);
        $newCustomerUnread  = $conv->customer_unread + 1;

        $conv->last_message_preview = $preview;
        $conv->last_message_at      = $msg->created_at;
        $conv->customer_unread      = $newCustomerUnread;
        $conv->save();

        $adminName = $admin->fullname ?? $admin->email;
        $payload   = $this->formatMessage($msg, $adminName, $this->orderLookupFor(collect([$msg])));

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

    /**
     * @param array<string, array{order_code: ?string, room_name: ?string}> $orderLookup
     */
    private function formatMessage(ChatMessage $msg, ?string $senderName = null, array $orderLookup = []): array
    {
        $orderInfo = $msg->order_id ? ($orderLookup[$msg->order_id] ?? null) : null;

        return [
            'id'          => $msg->id,
            'order_id'    => $msg->order_id,
            // Ngữ cảnh đơn của tin nhắn ngay tại đây — admin biết tin này nói về đơn nào mà không
            // cần tra cứu thêm, kể cả khi xem chung nhiều đơn trong 1 conversation (mặc định).
            'order_code'  => $orderInfo['order_code'] ?? null,
            'room_name'   => $orderInfo['room_name'] ?? null,
            'sender_type' => $msg->sender_type,
            'sender_id'   => $msg->sender_id,
            'sender_name' => $senderName,
            'body'        => $msg->body,
            'read_at'     => $msg->read_at?->toIso8601String(),
            'created_at'  => $msg->created_at->toIso8601String(),
        ];
    }

    /**
     * Dựng map order_id => {order_code, room_name} cho đúng các order_id xuất hiện trong
     * $messages — 1 query duy nhất, tránh N+1 khi format nhiều tin nhắn cùng lúc.
     *
     * @return array<string, array{order_code: ?string, room_name: ?string}>
     */
    private function orderLookupFor(\Illuminate\Support\Collection $messages): array
    {
        $orderIds = $messages->pluck('order_id')->filter()->unique()->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        return Order::whereIn('id', $orderIds)
            ->with('items.product:id,name')
            ->get()
            ->mapWithKeys(fn (Order $o) => [
                $o->id => [
                    'order_code' => $o->order_code,
                    'room_name'  => $o->items->first()?->product?->name,
                ],
            ])
            ->all();
    }

    private function buildOrderInfo(Order $order): array
    {
        $firstItem = $order->items->first();
        $product   = $firstItem?->product;

        $slots = $order->items->map(fn ($item) => [
            'date'  => $item->checkin_date?->format('Y-m-d'),
            'price' => (int) $item->price,
        ])->values()->toArray();

        $services = $order->services->map(fn ($s) => [
            'service_id'   => $s->service_id,
            'service_name' => $s->service_name,
            'price'        => $s->price,
            'quantity'     => $s->quantity,
            'subtotal'     => $s->subtotal,
        ])->values()->toArray();

        $slotsTotal    = (int) $order->items->sum('price');
        $servicesTotal = (int) $order->services->sum('subtotal');
        $depositPct    = $order->deposit_percent !== null ? (int) $order->deposit_percent : null;

        if ($depositPct !== null && $depositPct > 0 && $depositPct < 100) {
            $realFinal     = (int) round((int) $order->full_amount * 100 / $depositPct);
            $totalDiscount = max(0, (int) $order->amount - $realFinal);
        } else {
            $realFinal     = (int) $order->full_amount;
            $totalDiscount = max(0, (int) $order->amount - (int) $order->full_amount);
        }

        return [
            'order' => [
                'id'             => $order->id,
                'order_code'     => $order->order_code,
                'status'         => $order->status,
                'payment_method' => $order->payment_method,
                'expired_at'     => $order->expired_at,
                'buyer_name'     => $order->buyer_name,
                'buyer_phone'    => $order->buyer_phone,
            ],
            'room' => [
                'id'   => $product?->id,
                'name' => $product?->name,
            ],
            'slots'    => $slots,
            'services' => $services,
            'summary'  => [
                'slots_total'          => $slotsTotal,
                'discount_amount'      => $totalDiscount,
                'slots_final'          => max(0, $slotsTotal - $totalDiscount),
                'services_total'       => $servicesTotal,
                'total_after_discount' => $realFinal,
                'final_amount'         => (int) $order->full_amount,
            ],
            'deposit' => $depositPct !== null ? [
                'percentage'       => $depositPct,
                'deposit_amount'   => (int) $order->full_amount,
                'remaining_amount' => max(0, $realFinal - (int) $order->full_amount),
            ] : null,
        ];
    }
}
