<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Quản lý SỰ KIỆN (bảng `events`, model App\Models\Event) — nội dung marketing dùng chung, KHÔNG
 * scope theo đối tác (partner_id) — theo đúng tiền lệ của Banner (Modules/AppPage), khác với
 * Promotion (có phân quyền theo đối tác/chi nhánh). Ảnh + toggle on/off (is_active) là 2 trường cốt
 * lõi; title/description/sort_order chỉ để hiển thị danh sách quản lý cho gọn.
 */
class EventController extends Controller
{
    private const IMAGE_RULE_REQUIRED = 'required|image|mimes:jpg,jpeg,png,webp|max:5120';
    private const IMAGE_RULE_OPTIONAL = 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120';

    /**
     * GET /api/admin/events
     * Query params: search (theo title), is_active (1/0), per_page (mặc định 15)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Event::query();

        if ($request->filled('search')) {
            $query->where('title', 'like', '%'.$request->string('search').'%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $events = $query->orderBy('sort_order')->orderByDesc('id')->paginate($request->integer('per_page', 15));
        $events->getCollection()->transform(fn (Event $e) => $this->toListItem($e));

        return response()->json($events);
    }

    /**
     * GET /api/admin/events/{id}
     */
    public function show(int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['message' => 'Không tìm thấy sự kiện.'], 404);
        }

        return response()->json(['data' => $this->toListItem($event)]);
    }

    /**
     * POST /api/admin/events (multipart/form-data — ảnh bắt buộc khi tạo)
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
            'image'       => self::IMAGE_RULE_REQUIRED,
        ]);

        $event = Event::create([
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'sort_order'  => $data['sort_order'] ?? 0,
            'image'       => $request->file('image')->store('events', 'public'),
            'disk'        => 'public',
            'created_by'  => $user->id,
        ]);

        return response()->json(['data' => $this->toListItem($event)], 201);
    }

    /**
     * PUT|POST /api/admin/events/{id} (nhận cả PUT lẫn POST — dùng POST khi cần gửi kèm ảnh, PHP
     * không tự parse multipart cho method PUT thật). Ảnh tuỳ chọn — không gửi thì giữ ảnh cũ; gửi
     * ảnh mới thì xoá file cũ khỏi disk.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['message' => 'Không tìm thấy sự kiện.'], 404);
        }

        $data = $request->validate([
            'title'       => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer',
            'image'       => self::IMAGE_RULE_OPTIONAL,
        ]);

        $event->update(collect($data)->only(['title', 'description', 'is_active', 'sort_order'])->toArray());

        if ($request->hasFile('image')) {
            $oldImage = $event->image;
            $event->update(['image' => $request->file('image')->store('events', 'public')]);
            if ($oldImage) {
                Storage::disk('public')->delete($oldImage);
            }
        }

        return response()->json(['data' => $this->toListItem($event->fresh())]);
    }

    /**
     * POST /api/admin/events/{id}/toggle
     * Bật/tắt nhanh 1 sự kiện mà không cần gửi lại cả form — cùng kiểu với
     * Admin\UnlockController::openGate() (toggle cờ boolean, trả về state mới).
     */
    public function toggle(int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['message' => 'Không tìm thấy sự kiện.'], 404);
        }

        $event->update(['is_active' => ! $event->is_active]);

        return response()->json([
            'success'   => true,
            'is_active' => $event->is_active,
        ]);
    }

    /**
     * DELETE /api/admin/events/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $event = Event::find($id);

        if (! $event) {
            return response()->json(['message' => 'Không tìm thấy sự kiện.'], 404);
        }

        if ($event->image) {
            Storage::disk($event->disk ?? 'public')->delete($event->image);
        }

        $event->delete();

        return response()->json(['message' => 'Đã xoá sự kiện.']);
    }

    private function toListItem(Event $event): array
    {
        return [
            'id'          => $event->id,
            'title'       => $event->title,
            'description' => $event->description,
            'image_url'   => $event->image_url,
            'is_active'   => (bool) $event->is_active,
            'sort_order'  => $event->sort_order,
            'created_by'  => $event->created_by,
            'created_at'  => optional($event->created_at)->toISOString(),
            'updated_at'  => optional($event->updated_at)->toISOString(),
        ];
    }
}
