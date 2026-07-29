<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;

class CccdController extends Controller
{
    /**
     * POST /api/admin/cccd/scan
     * Quét 1 cặp ảnh CCCD (mặt trước + sau) và trả về dữ liệu đọc được ngay, KHÔNG gắn vào đơn/
     * khách hàng nào — dùng cho FE hiển thị preview thông tin từng khách trước khi submit đơn.
     *
     * Dùng cho luồng nhập CCCD nhiều khách (khung giờ qua đêm, guest_count > 1): FE tự lặp gọi API
     * này đúng (guest_count) lần — 1 lần cho khách chính + (guest_count - 1) lần cho khách đi cùng —
     * gửi kèm guest_index để nhận lại đúng thứ tự đang nhập; cùng nguyên tắc guest_index bắt đầu
     * từ 2 cho khách đi cùng như Admin\BookingController::store().
     *
     * Quét lỗi (QR mờ/không đọc được) KHÔNG coi là lỗi request — trả 200 kèm data=null, scanned=false
     * để FE cho phép admin/lễ tân tự nhập tay, giống toàn bộ luồng CCCD "tùy chọn" hiện có.
     *
     * Body (multipart/form-data):
     *  - front       : file ảnh mặt trước CCCD (bắt buộc)
     *  - back        : file ảnh mặt sau CCCD (bắt buộc)
     *  - guest_index : số thứ tự khách (tùy chọn) — chỉ echo lại nguyên trong response để FE map
     *                  đúng ô đang nhập, không dùng để xử lý gì thêm.
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'front'       => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
            'back'        => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
            'guest_index' => 'sometimes|nullable|integer|min:1',
        ]);

        $front = $request->file('front')->store('cccd', 'public');
        $back  = $request->file('back')->store('cccd', 'public');

        $data = null;
        try {
            $data = app(CccdScannerService::class)->scanPaths($front, $back);
        } catch (\Throwable $e) {
            Log::warning('Admin API: quét CCCD (endpoint scan độc lập) thất bại', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'guest_index'    => $request->filled('guest_index') ? $request->integer('guest_index') : null,
            'cccd_front'     => $front,
            'cccd_back'      => $back,
            'cccd_front_url' => Storage::disk('public')->url($front),
            'cccd_back_url'  => Storage::disk('public')->url($back),
            'scanned'        => $data !== null,
            'data'           => $data,
        ]);
    }
}
