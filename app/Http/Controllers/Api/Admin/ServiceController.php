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
     * dùng đúng $room->additionalServices()), và để gán dịch vụ bổ sung cho phòng
     * (POST /api/admin/products, additional_services[] — xem ProductController).
     *
     * Phạm vi hiển thị: super_admin xem tất cả; user thường chỉ xem dịch vụ thuộc đối tác mình
     * (additional_services.partner_id) — cùng nguyên tắc với RoomController::index().
     *
     * Điều kiện hiển thị is_active=true LUÔN áp dụng mặc định (khớp modifyQueryUsing của field
     * additionalServices trong ProductForm — chỉ dịch vụ đang bật mới được chọn để gán vào phòng);
     * truyền ?is_active= để chủ động xem cả dịch vụ đang tắt khi thật sự cần.
     *
     * Query params:
     *  - is_active : ghi đè điều kiện mặc định (1/0/true/false) — bỏ trống mặc định chỉ lấy is_active=true
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

        $query->where('is_active', $request->has('is_active') ? $request->boolean('is_active') : true);

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
