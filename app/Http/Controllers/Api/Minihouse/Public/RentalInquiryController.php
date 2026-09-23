<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\RentalInquiry;

// Gửi "yêu cầu liên hệ thuê phòng" — CÔNG KHAI, không cần đăng nhập. Chỉ là 1 lead để nhân viên gọi
// lại tư vấn (xem RentalInquiry), KHÔNG tự tạo Tenant/Contract nào.
class RentalInquiryController extends Controller
{
    /**
     * POST /api/minihouse/public/rental-inquiries
     * Body: { full_name, phone, room_id?, building_id?, email?, note?, preferred_move_in_date? }
     * Phải có ÍT NHẤT 1 trong 2: room_id (quan tâm 1 phòng cụ thể) hoặc building_id (quan tâm 1 toà
     * nói chung). Nếu truyền room_id, building_id tự suy ra từ chính phòng đó (bỏ qua giá trị client
     * gửi nếu có, tránh lệch nhau).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'               => 'required|string|max:255',
            'phone'                   => 'required|string|max:20',
            'email'                   => 'nullable|email|max:255',
            // KHÔNG ép định dạng 'uuid' — products.id hiện sinh dạng ULID (VD "01m36x...", 26 ký
            // tự), không khớp regex UUID chuẩn của Laravel dù cột CSDL vẫn là char(36) (đủ chỗ chứa
            // cả 2 dạng) — validate('uuid') sẽ luôn báo lỗi sai cho MỌI room_id hợp lệ thực tế.
            'room_id'                 => 'nullable|string|exists:products,id',
            'building_id'             => 'required_without:room_id|nullable|integer|exists:categories,id',
            'note'                    => 'nullable|string|max:2000',
            'preferred_move_in_date'  => 'nullable|date',
        ]);

        if (! empty($data['room_id'])) {
            $room = Room::find($data['room_id']);

            if (! $room) {
                return response()->json(['message' => 'Phòng không hợp lệ.'], 422);
            }

            $data['building_id'] = $room->building_id;
        }

        $inquiry = RentalInquiry::create($data);

        return response()->json([
            'message' => 'Đã ghi nhận yêu cầu. Nhân viên sẽ liên hệ lại với bạn trong thời gian sớm nhất.',
            'data'    => ['id' => $inquiry->id],
        ], 201);
    }
}
