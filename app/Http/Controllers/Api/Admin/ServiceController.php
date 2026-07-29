<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\BladeThemeV1\App\Models\AdditionService;

class ServiceController extends Controller
{
    /**
     * GET /api/admin/services
     * Danh sách dịch vụ bổ sung (additional_services) — dùng để admin chọn service_id khi tạo/sửa
     * đơn (POST /api/admin/orders, services[].service_id — xem BuildsRoomBooking::buildServices(),
     * dùng đúng $room->additionalServices()).
     *
     * Phạm vi hiển thị: super_admin xem tất cả; user thường chỉ xem dịch vụ thuộc đối tác mình
     * (additional_services.partner_id) — cùng nguyên tắc với RoomController::index().
     *
     * Query params:
     *  - is_active : lọc theo trạng thái hiển thị (1/0/true/false)
     *  - search    : lọc theo tên dịch vụ
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $query = AdditionService::query();

        if (! $user->isSuperAdmin()) {
            $query->where('partner_id', $user->partner_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->string('search') . '%');
        }

        $services = $query->orderBy('name')
            ->get(['id', 'name', 'price', 'image', 'is_active'])
            ->map(fn (AdditionService $service) => [
                'id'        => $service->id,
                'name'      => $service->name,
                'price'     => $service->price,
                'image'     => $service->image,
                'image_url' => $service->image ? Storage::disk('public')->url($service->image) : null,
                'is_active' => $service->is_active,
            ])
            ->values();

        return response()->json(['data' => $services]);
    }
}
