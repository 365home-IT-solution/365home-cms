<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\ChatConversation;
use Modules\Minihouse\App\Models\ChatMessage;
use Modules\Minihouse\App\Services\MinihouseChatService;

// Chat khách thuê <-> nhân viên (phía nhân viên/admin) — mirror App\Http\Controllers\Api\Admin\
// ChatController (Home) nhưng lọc theo building_id THẲNG trên conversation (Home phải đi vòng qua
// messages->order->category_id vì ChatConversation của Home không có cột chi nhánh) — dùng CHUNG
// ScopesToMinihouseBuilding với mọi controller MiniHouse khác để không lệch ranh giới toà nhà giữa
// panel Filament và API.
class ChatController extends Controller
{
    use ScopesToMinihouseBuilding;

    public function __construct(private readonly MinihouseChatService $chat)
    {
    }

    // GET /api/admin/minihouse/chat — danh sách conversation, sắp theo tin mới nhất, lọc theo
    // đúng phạm vi toà nhà tài khoản đang đăng nhập được phép xem.
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem tin nhắn.'], 403);
        }

        $buildingIds = $this->permittedBuildingIds($request);

        $query = ChatConversation::with('tenant:id,fullname,phone')
            ->orderByDesc('last_message_at');

        if (! empty($buildingIds)) {
            $query->whereIn('building_id', $buildingIds);
        }

        $conversations = $query->paginate($request->integer('per_page', 20));

        $items = collect($conversations->items())->map(fn (ChatConversation $conv) => [
            'id'                   => $conv->id,
            'status'               => $conv->status,
            'admin_unread'         => $conv->admin_unread,
            'last_message_preview' => $conv->last_message_preview,
            'last_message_at'      => $conv->last_message_at?->toIso8601String(),
            'tenant'               => $conv->tenant ? [
                'id'       => $conv->tenant->id,
                'fullname' => $conv->tenant->fullname,
                'phone'    => $conv->tenant->phone,
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

    // GET /api/admin/minihouse/chat/{id} — chi tiết + 20 tin nhắn gần nhất, tự đánh dấu đã đọc.
    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem tin nhắn.'], 403);
        }

        $conversation = $this->findOwned($request, $id);
        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        $this->chat->markReadByAdmin($conversation);

        return response()->json([
            'conversation' => [
                'id'              => $conversation->id,
                'status'          => $conversation->status,
                'admin_unread'    => 0,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'tenant'          => $conversation->tenant ? [
                    'id'       => $conversation->tenant->id,
                    'fullname' => $conversation->tenant->fullname,
                    'phone'    => $conversation->tenant->phone,
                ] : null,
            ],
            ...$this->chat->recentMessages($conversation, $this->contractId($request)),
        ]);
    }

    // GET /api/admin/minihouse/chat/{id}/messages?before_id=&limit=&contract_id= — tải thêm tin nhắn cũ hơn.
    public function messages(Request $request, string $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_tenants')) {
            return response()->json(['message' => 'Không có quyền xem tin nhắn.'], 403);
        }

        $conversation = $this->findOwned($request, $id);
        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        return response()->json(
            $this->chat->olderMessages(
                $conversation,
                $request->query('before_id'),
                $this->contractId($request),
                (int) $request->integer('limit', 20)
            )
        );
    }

    // POST /api/admin/minihouse/chat/{id}/messages — nhân viên gửi tin nhắn (contract_id null = hỗ trợ chung).
    public function send(Request $request, string $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền gửi tin nhắn.'], 403);
        }

        $conversation = $this->findOwned($request, $id);
        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        $data = $request->validate([
            'body'        => 'required|string|max:2000',
            'contract_id' => 'nullable|integer',
        ]);

        $admin   = $request->user();
        $name    = $admin->fullname ?? $admin->email;
        $message = $this->chat->send($conversation, ChatMessage::SENDER_ADMIN, (string) $admin->id, $data['body'], $name, $data['contract_id'] ?? null);

        return response()->json(['message' => $this->chat->formatMessage($message, $name)], 201);
    }

    // POST /api/admin/minihouse/chat/{id}/read
    public function read(Request $request, string $id): JsonResponse
    {
        $conversation = $this->findOwned($request, $id);
        if ($conversation instanceof JsonResponse) {
            return $conversation;
        }

        $this->chat->markReadByAdmin($conversation);

        return response()->json(['ok' => true]);
    }

    private function contractId(Request $request): ?int
    {
        return $request->filled('contract_id') ? (int) $request->query('contract_id') : null;
    }

    private function findOwned(Request $request, string $id): ChatConversation|JsonResponse
    {
        $conversation = ChatConversation::with('tenant:id,fullname,phone')->find($id);

        if (! $conversation) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        // Conversation chưa có building_id (dữ liệu cũ/hợp đồng đã hết trước khi chat) — không
        // chặn xem, cùng nguyên tắc rootBuildingIds() rỗng = không giới hạn ở nơi khác trong module.
        if ($conversation->building_id && ! $this->isBuildingAllowed($request, $conversation->building_id)) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        return $conversation;
    }
}
