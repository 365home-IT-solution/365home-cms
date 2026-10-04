<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse\Portal;

use App\Http\Controllers\Controller;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Support\CccdScanMapper;

class CccdController extends Controller
{
    /**
     * POST /api/minihouse/portal/cccd/scan-qr
     * Khách thuê tự quét 1 ảnh CCCD mặt có mã QR — endpoint TUỲ CHỌN, CHỈ ĐỌC: không lưu ảnh, không
     * sửa hồ sơ khách thuê (thông tin CCCD của khách thuê do nhân viên quản lý, xem
     * Admin\Minihouse\TenantController). Dùng chung quy tắc với app khách Home
     * (CccdIntakeService::readStrict): chỉ nhận dữ liệu từ QR, kiểm tra cấu trúc số CCCD, giới hạn
     * lượt quét theo IP + khách thuê. Lỗi ném CccdIntakeException → 422/429 {message, code, field}.
     *
     * Tuổi KHÔNG chặn (khách thuê có thể là trẻ ở cùng cha mẹ) — trả age/min_age/under_age, mốc
     * tham khảo CccdScanMapper::TENANT_MIN_AGE.
     *
     * matches_profile: số CCCD vừa quét có khớp id_card_number trong hồ sơ không (null nếu hồ sơ
     * chưa có số CCCD).
     *
     * Body (multipart/form-data):
     *  - cccd_qr_image : file ảnh mặt có mã QR (bắt buộc) — JPG/PNG/WEBP, tối đa 5MB
     */
    public function scanQr(Request $request, CccdIntakeService $intake): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        ['data' => $data] = $intake->readFromRequest($request, 'cccd_qr_image', [], 'cccd_qr_image', true, 'tenant:' . $tenant->getKey());

        $age           = CccdIdentity::ageOn($data);
        $profileNumber = trim((string) $tenant->id_card_number);

        return response()->json([
            'scanned'         => true,
            'data'            => $data,
            'tenant_fields'   => CccdScanMapper::toTenantApiFields($data),
            'matches_profile' => $profileNumber !== '' ? $profileNumber === $data['cccd'] : null,
            'age'             => $age,
            'min_age'         => CccdScanMapper::TENANT_MIN_AGE,
            'under_age'       => $age !== null && $age < CccdScanMapper::TENANT_MIN_AGE,
        ]);
    }
}
