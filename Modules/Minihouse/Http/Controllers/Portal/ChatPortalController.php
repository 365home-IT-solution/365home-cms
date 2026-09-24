<?php

namespace Modules\Minihouse\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Services\MinihouseChatService;

// Portal chat khách thuê ĐÃ ĐĂNG NHẬP (guard "tenant", session — GIAO DIỆN TRÊN WEBSITE) — mirror
// TenantPortalController (web) nhưng dùng CHUNG MinihouseChatService với bản API Sanctum
// (App\Http\Controllers\Api\Minihouse\Portal\ChatController) VÀ Filament (nhân viên), để 3 nơi
// không lệch nhau — đúng nguyên tắc TenantPortalService/InteractsWithTenantPortalData đã áp dụng
// cho phần hợp đồng/hoá đơn trong module này.
//
// Trang chỉ render 1 khung chat (giống widget), toàn bộ gửi/tải thêm tin nhắn đi qua 2 endpoint
// AJAX bên dưới (messages()/send()) — nhận thẳng session hiện có, KHÔNG cần token Sanctum riêng như
// bản API cho app di động.
class ChatPortalController extends Controller
{
    public function __construct(private readonly MinihouseChatService $chat)
    {
    }

    public function show(Request $request): View
    {
        $conversation = $this->chat->conversationFor($this->tenant());
        $this->chat->markReadByTenant($conversation);

        return view('minihouse::portal.chat', [
            'conversation' => $conversation,
            ...$this->chat->recentMessages($conversation, $this->contractId($request)),
        ]);
    }

    // GET /minihouse/portal/chat/messages?before_id=&limit=&contract_id= — AJAX tải thêm tin nhắn cũ hơn.
    public function messages(Request $request): JsonResponse
    {
        $conversation = $this->chat->conversationFor($this->tenant());

        return response()->json(
            $this->chat->olderMessages(
                $conversation,
                $request->query('before_id'),
                $this->contractId($request),
                (int) $request->integer('limit', 20)
            )
        );
    }

    // POST /minihouse/portal/chat/messages — AJAX gửi tin nhắn (contract_id null = hỗ trợ chung).
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body'        => 'required|string|max:2000',
            'contract_id' => 'nullable|integer',
        ]);

        $tenant       = $this->tenant();
        $conversation = $this->chat->conversationFor($tenant);
        $message      = $this->chat->send($conversation, 'tenant', (string) $tenant->id, $data['body'], null, $data['contract_id'] ?? null);

        return response()->json(['message' => $this->chat->formatMessage($message)], 201);
    }

    // POST /minihouse/portal/chat/read — AJAX đánh dấu đã đọc (gọi khi tab đang mở nhận tin mới).
    public function read(): JsonResponse
    {
        $this->chat->markReadByTenant($this->chat->conversationFor($this->tenant()));

        return response()->json(['ok' => true]);
    }

    private function contractId(Request $request): ?int
    {
        return $request->filled('contract_id') ? (int) $request->query('contract_id') : null;
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Auth::guard('tenant')->user();

        return $tenant;
    }
}
