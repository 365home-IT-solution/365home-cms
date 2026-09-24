<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\MinihouseChatService;

// Bản API (token Sanctum, app di động/bên thứ 3) của Portal chat khách thuê — mirror
// App\Http\Controllers\Api\ChatController (Home), kể cả chia luồng theo TỪNG HỢP ĐỒNG (contract_id,
// mirror order_id của Home). Dùng CHUNG MinihouseChatService với bản web session
// (Modules\Minihouse\Http\Controllers\Portal\ChatPortalController) để không lệch nhau — đúng nguyên
// tắc TenantPortalService đã áp dụng cho phần hợp đồng/hoá đơn.
class ChatController extends Controller
{
    public function __construct(private readonly MinihouseChatService $chat)
    {
    }

    // GET /api/minihouse/portal/chat?contract_id= — lấy/tạo conversation + 20 tin nhắn gần nhất của
    // đúng luồng (contract_id null = hỗ trợ chung), tự đánh dấu đã đọc tin từ admin.
    public function show(Request $request): JsonResponse
    {
        $conversation = $this->chat->conversationFor($this->tenant($request));
        $contractId   = $this->contractId($request);

        $this->chat->markReadByTenant($conversation);

        return response()->json([
            'conversation' => [
                'id'              => $conversation->id,
                'status'          => $conversation->status,
                'tenant_unread'   => 0,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ],
            ...$this->chat->recentMessages($conversation, $contractId),
        ]);
    }

    // GET /api/minihouse/portal/chat/messages?before_id=&limit=&contract_id= — tải thêm tin nhắn cũ hơn.
    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->chat->conversationFor($this->tenant($request));

        return response()->json(
            $this->chat->olderMessages(
                $conversation,
                $request->query('before_id'),
                $this->contractId($request),
                (int) $request->integer('limit', 20)
            )
        );
    }

    // POST /api/minihouse/portal/chat/messages — khách thuê gửi tin nhắn (contract_id null = hỗ trợ chung).
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body'        => 'required|string|max:2000',
            'contract_id' => 'nullable|integer',
        ]);

        $tenant       = $this->tenant($request);
        $conversation = $this->chat->conversationFor($tenant);
        $message      = $this->chat->send($conversation, 'tenant', (string) $tenant->id, $data['body'], null, $data['contract_id'] ?? null);

        return response()->json(['message' => $this->chat->formatMessage($message)], 201);
    }

    // POST /api/minihouse/portal/chat/read — đánh dấu đã đọc mọi tin từ admin.
    public function read(Request $request): JsonResponse
    {
        $this->chat->markReadByTenant($this->chat->conversationFor($this->tenant($request)));

        return response()->json(['ok' => true]);
    }

    private function contractId(Request $request): ?int
    {
        return $request->filled('contract_id') ? (int) $request->query('contract_id') : null;
    }

    private function tenant(Request $request): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        return $tenant;
    }
}
