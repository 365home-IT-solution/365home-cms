<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Announcement;

// Thông báo chung chủ nhà tự đăng cho khách thuê xem trong Portal (VD cắt nước, bảo trì thang máy).
// building_id NULL = gửi TẤT CẢ khách thuê mọi toà. Tạo xong AnnouncementObserver tự "phát" ngay ra
// Portal (PortalNotification) + Thông báo đẩy cho khách đang có hợp đồng "Đang hiệu lực" liên quan —
// controller này KHÔNG tự gửi, chỉ tạo bản ghi. KHÔNG dùng ScopesToMinihouseBuilding để chặn theo
// building_id được quản lý — Announcement CỐ Ý không áp ranh giới toà nhà (đúng hành vi Filament
// AnnouncementForm hiện có: mọi tài khoản có quyền đều chọn được "Tất cả toà nhà" hoặc bất kỳ toà
// nào), chỉ chặn theo quyền chung 'announcements'.
class AnnouncementController extends Controller
{
    // GET /api/admin/minihouse/announcements?per_page=
    public function index(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'view_any_announcements')) {
            return response()->json(['message' => 'Không có quyền xem thông báo.'], 403);
        }

        $announcements = Announcement::with('building:id,name', 'createdBy:id,fullname')
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 20));

        $announcements->getCollection()->transform(fn (Announcement $a) => $this->toItem($a));

        return response()->json($announcements);
    }

    // POST /api/admin/minihouse/announcements
    public function store(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_announcements')) {
            return response()->json(['message' => 'Không có quyền tạo thông báo.'], 403);
        }

        $data = $request->validate([
            'building_id' => 'nullable|integer|exists:minihouse_buildings,id',
            'title'       => 'required|string|max:255',
            'body'        => 'required|string',
        ]);

        $announcement = Announcement::create($data + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $this->toItem($announcement->fresh(['building', 'createdBy']))], 201);
    }

    // DELETE /api/admin/minihouse/announcements/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $this->hasPermission($request, 'delete_announcements')) {
            return response()->json(['message' => 'Không có quyền xoá thông báo.'], 403);
        }

        $announcement = Announcement::find($id);

        if (! $announcement) {
            return response()->json(['message' => 'Không tìm thấy thông báo.'], 404);
        }

        $announcement->delete();

        return response()->json(['message' => 'Đã xoá thông báo.']);
    }

    // super_admin bypass qua Gate::before — cùng quy ước ScopesToMinihouseBuilding::hasPermission()
    // của các controller khác, viết lại tại đây vì controller này không cần phần scope-theo-toà còn
    // lại của trait đó.
    private function hasPermission(Request $request, string $permission): bool
    {
        $user = $request->user();

        return ($user instanceof \App\Models\User) && ($user->isSuperAdmin() || $user->can($permission));
    }

    private function toItem(Announcement $announcement): array
    {
        return [
            'id'           => $announcement->id,
            'building_id'  => $announcement->building_id,
            'building_name' => $announcement->building?->name,
            'title'        => $announcement->title,
            'body'         => $announcement->body,
            'created_by'   => $announcement->created_by,
            'created_by_name' => $announcement->createdBy?->fullname,
            'created_at'   => $announcement->created_at?->toIso8601String(),
        ];
    }
}
