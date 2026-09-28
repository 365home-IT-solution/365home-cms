<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\CccdIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CccdController extends Controller
{
    /**
     * POST /api/admin/cccd/scan
     * Quét ảnh CCCD và trả về dữ liệu đọc được ngay, KHÔNG gắn vào đơn/khách hàng nào — dùng cho FE
     * hiển thị preview thông tin từng khách trước khi submit đơn.
     *
     * Dùng cho luồng nhập CCCD nhiều khách (khung giờ qua đêm, guest_count > 1): FE tự lặp gọi API
     * này đúng (guest_count) lần — 1 lần cho khách chính + (guest_count - 1) lần cho khách đi cùng —
     * gửi kèm guest_index để nhận lại đúng thứ tự đang nhập; cùng nguyên tắc guest_index bắt đầu
     * từ 2 cho khách đi cùng như Admin\BookingController::store().
     *
     * CHỈ QUÉT, KHÔNG LƯU FILE — trước đây mỗi lần quét lưu vĩnh viễn 2 ảnh không gắn vào đâu (file
     * CCCD mồ côi, truy cập công khai được). Ảnh thật được lưu khi submit đơn/khách hàng.
     *
     * Quét lỗi (QR mờ/không đọc được) KHÔNG coi là lỗi request — trả 200 kèm data=null, scanned=false
     * để FE cho phép admin/lễ tân tự nhập tay. Các ảnh là CCCD của 2 người khác nhau → 422
     * code=cccd_mismatch.
     *
     * Body (multipart/form-data), gửi ÍT NHẤT 1 ảnh:
     *  - qr_image    : ảnh mặt có mã QR
     *  - front, back : ảnh mặt trước / mặt sau (tuỳ chọn, để đối chứng)
     *  - guest_index : số thứ tự khách (tùy chọn) — chỉ echo lại nguyên trong response để FE map
     *                  đúng ô đang nhập, không dùng để xử lý gì thêm.
     */
    public function scan(Request $request, CccdIntakeService $intake): JsonResponse
    {
        $request->validate([
            'qr_image'    => 'required_without_all:front,back|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'front'       => 'required_without_all:qr_image,back|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'back'        => 'required_without_all:qr_image,front|nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guest_index' => 'sometimes|nullable|integer|min:1',
        ]);

        $read = $intake->readForAdmin([
            'cccd_qr_image' => $request->file('qr_image'),
            'cccd_front'    => $request->file('front'),
            'cccd_back'     => $request->file('back'),
        ]);

        return response()->json([
            'guest_index' => $request->filled('guest_index') ? $request->integer('guest_index') : null,
            'scanned'     => $read['data'] !== null,
            'data'        => $read['data'],
            // checks[cccd_qr_image|cccd_front|cccd_back] = match|unreadable cho từng ảnh đã gửi.
            'checks'      => $read['checks'],
            'warnings'    => $read['warnings'],
        ]);
    }
}
