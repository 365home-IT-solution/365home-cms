<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quét 1 ảnh CCCD mặt có mã QR và trả về dữ liệu đọc được — các endpoint ĐỘC LẬP, tuỳ chọn: KHÔNG
 * lưu ảnh, KHÔNG gắn vào đơn/hồ sơ nào, KHÔNG thay đổi gì ở các API đặt phòng/hồ sơ hiện có (vẫn
 * nhận cccd_front + cccd_back như cũ). FE gọi để kiểm tra/hiển thị thông tin CCCD trước khi tự xử
 * lý tiếp.
 *
 * Tách riêng theo đối tượng, giống orders / guest/orders:
 *  - POST /api/guest/cccd/scan-qr → khách vãng lai (không đăng nhập)
 *  - POST /api/cccd/scan-qr       → khách đã đăng nhập (auth:sanctum + customer.active)
 * Bản quản trị: Admin\CccdController::scanQr() (homestay), Admin\Minihouse\CccdController (minihouse).
 *
 * Dùng chung quy tắc với web (CccdIntakeService::readStrict): chỉ nhận dữ liệu từ QR (không OCR),
 * kiểm tra cấu trúc số CCCD, có giới hạn lượt quét (config cccd.scan_limit).
 *
 * Body (multipart/form-data), giống nhau ở cả 2 endpoint:
 *  - cccd_qr_image : file ảnh mặt có mã QR (bắt buộc) — JPG/PNG/WEBP, tối đa 5MB
 *  - checkin_date  : Y-m-d (tuỳ chọn) — mốc tính tuổi, mặc định hôm nay
 *  - guest_index   : số thứ tự khách (tuỳ chọn) — chỉ echo lại để FE map đúng ô đang nhập
 *
 * Tuổi KHÔNG chặn ở đây (endpoint không biết đơn có qua đêm hay không) — trả age/under_age để FE
 * tự quyết. Lỗi CCCD ném CccdIntakeException → 422/429 {message, code, field}.
 */
class CccdController extends Controller
{
    // Khách vãng lai — giới hạn lượt quét chỉ tính theo IP.
    public function scanQrGuest(Request $request, CccdIntakeService $intake): JsonResponse
    {
        return response()->json($this->scan($request, $intake, null));
    }

    /**
     * Khách đã đăng nhập — giới hạn lượt quét tính thêm theo tài khoản. Trả thêm đối chiếu với hồ
     * sơ (chỉ đọc, không ghi gì vào hồ sơ):
     *  - same_as_profile : CCCD vừa quét có phải của chính chủ tài khoản không (null nếu hồ sơ
     *                      chưa có dữ liệu CCCD)
     *  - companion_id    : id người đi cùng đã lưu trong hồ sơ trùng với CCCD này (null nếu không có)
     */
    public function scanQr(Request $request, CccdIntakeService $intake): JsonResponse
    {
        $customer = $request->user();
        $payload  = $this->scan($request, $intake, 'customer:' . $customer->getKey());
        $data     = $payload['data'];

        $profile   = $customer instanceof Customer && is_array($customer->cccd_data) && $customer->cccd_data ? $customer->cccd_data : null;
        $companion = $customer instanceof Customer
            ? $customer->companions()->get()->first(fn ($c) => is_array($c->cccd_data) && $c->cccd_data && CccdIdentity::samePerson($data, $c->cccd_data))
            : null;

        return response()->json($payload + [
            'same_as_profile' => $profile ? CccdIdentity::samePerson($data, $profile) : null,
            'companion_id'    => $companion?->id,
        ]);
    }

    private function scan(Request $request, CccdIntakeService $intake, ?string $actor): array
    {
        $request->validate([
            'checkin_date' => 'sometimes|nullable|date_format:Y-m-d',
            'guest_index'  => 'sometimes|nullable|integer|min:1',
        ]);

        ['data' => $data] = $intake->readFromRequest($request, 'cccd_qr_image', [], 'cccd_qr_image', true, $actor);

        $on  = $request->filled('checkin_date') ? Carbon::createFromFormat('!Y-m-d', $request->input('checkin_date')) : null;
        $age = CccdIdentity::ageOn($data, $on);

        return [
            'scanned'        => true,
            'guest_index'    => $request->filled('guest_index') ? $request->integer('guest_index') : null,
            'data'           => $data,
            'birth_province' => CccdIdentity::birthProvince($data['cccd']),
            'age'            => $age,
            'min_age'        => CccdIdentity::MIN_AGE,
            'under_age'      => $age !== null && $age < CccdIdentity::MIN_AGE,
        ];
    }
}
