<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CccdController extends Controller
{
    /**
     * POST /api/cccd/scan-qr
     * Quét 1 ảnh CCCD mặt có mã QR và trả về dữ liệu đọc được — endpoint ĐỘC LẬP, tuỳ chọn: KHÔNG
     * lưu ảnh, KHÔNG gắn vào đơn/hồ sơ nào, KHÔNG thay đổi gì ở các API đặt phòng/hồ sơ hiện có
     * (vẫn nhận cccd_front + cccd_back như cũ). FE gọi để kiểm tra/hiển thị thông tin CCCD trước
     * khi tự xử lý tiếp.
     *
     * Dùng chung quy tắc với web (CccdIntakeService::readStrict): chỉ nhận dữ liệu từ QR (không
     * OCR), kiểm tra cấu trúc số CCCD, có rate limit theo IP + tài khoản. Không cần đăng nhập
     * (khách vãng lai cũng đặt phòng được); có gửi Bearer token thì rate limit tính theo tài khoản.
     *
     * Body (multipart/form-data):
     *  - cccd_qr_image : file ảnh mặt có mã QR (bắt buộc) — JPG/PNG/WEBP, tối đa 5MB
     *  - checkin_date  : Y-m-d (tuỳ chọn) — mốc tính tuổi, mặc định hôm nay
     *  - guest_index   : số thứ tự khách (tuỳ chọn) — chỉ echo lại để FE map đúng ô đang nhập
     *
     * Tuổi KHÔNG chặn ở đây (endpoint không biết đơn có qua đêm hay không) — trả age/under_age để
     * FE tự quyết. Lỗi CCCD ném CccdIntakeException → 422/429 {message, code, field}.
     */
    public function scanQr(Request $request, CccdIntakeService $intake): JsonResponse
    {
        $request->validate([
            'checkin_date' => 'sometimes|nullable|date_format:Y-m-d',
            'guest_index'  => 'sometimes|nullable|integer|min:1',
        ]);

        $user  = $request->user('sanctum');
        $actor = $user ? class_basename($user) . ':' . $user->getKey() : null;

        ['data' => $data] = $intake->readFromRequest($request, 'cccd_qr_image', [], 'cccd_qr_image', true, $actor);

        $on  = $request->filled('checkin_date') ? Carbon::createFromFormat('!Y-m-d', $request->input('checkin_date')) : null;
        $age = CccdIdentity::ageOn($data, $on);

        return response()->json([
            'scanned'        => true,
            'guest_index'    => $request->filled('guest_index') ? $request->integer('guest_index') : null,
            'data'           => $data,
            'birth_province' => CccdIdentity::birthProvince($data['cccd']),
            'age'            => $age,
            'min_age'        => CccdIdentity::MIN_AGE,
            'under_age'      => $age !== null && $age < CccdIdentity::MIN_AGE,
        ]);
    }
}
