<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Product\App\Models\RoomType;

/**
 * Danh sách Danh mục phòng (bảng `room_types`, vd "Theo giờ", "Theo ngày"...) — dùng cho dropdown
 * lọc `room_type_id` ở GET /api/admin/products và form tạo/sửa phòng. Đây là danh mục DÙNG CHUNG
 * cho mọi đối tác (không có partner_id/global scope theo partner như `products`), khác với
 * App\Http\Controllers\Api\RoomTypeController (API phía khách hàng, chỉ trả room_type is_active
 * kèm danh sách chi nhánh/phòng — mục đích khác hẳn, không dùng cho admin quản trị).
 */
class RoomTypeController extends Controller
{
    /**
     * GET /api/admin/room-types
     * Query params:
     *  - is_active : lọc theo trạng thái hiển thị — 1/0/true/false. Mặc định (không truyền) trả
     *                TẤT CẢ (kể cả đang tắt) vì admin cần thấy toàn bộ danh mục để quản lý, khác
     *                API khách hàng luôn ép is_active=true.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoomType::query();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $roomTypes = $query->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'slug', 'name', 'icon', 'icon_url', 'is_active', 'sort_order']);

        return response()->json(['data' => $roomTypes]);
    }
}
