<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use App\Services\CccdIntakeService;
use App\Support\CccdIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Support\CccdScanMapper;

class CccdController extends Controller
{
    use ScopesToMinihouseBuilding;

    /**
     * POST /api/admin/minihouse/cccd/scan-qr
     * Quét 1 ảnh CCCD mặt có mã QR cho form Khách thuê — endpoint TUỲ CHỌN, thêm mới: không lưu
     * ảnh, không tạo/sửa khách thuê nào, không thay đổi TenantController (vẫn nhận id_card_front +
     * id_card_back và tự quét ngầm khi lưu qua TenantObserver như cũ). Chỉ đọc QR, không OCR.
     *
     * Trả thêm tenant_fields — dữ liệu đã map sẵn sang đúng tên/định dạng field của
     * POST /api/admin/minihouse/tenants để FE điền thẳng vào form.
     *
     * Không đọc được QR KHÔNG coi là lỗi request — trả 200 scanned=false kèm warnings để nhân viên
     * nhập tay. Quyền: tạo hoặc sửa khách thuê.
     *
     * Tuổi KHÔNG chặn (khách thuê có thể là trẻ ở cùng cha mẹ) — trả age/min_age/under_age để FE
     * tự cảnh báo, mốc tham khảo CccdScanMapper::TENANT_MIN_AGE.
     *
     * Body (multipart/form-data):
     *  - cccd_qr_image : file ảnh mặt có mã QR (bắt buộc) — JPG/PNG/WEBP, tối đa 10MB
     */
    public function scanQr(Request $request, CccdIntakeService $intake): JsonResponse
    {
        if (! $this->hasPermission($request, 'create_tenants') && ! $this->hasPermission($request, 'update_tenants')) {
            return response()->json(['message' => 'Không có quyền quét CCCD khách thuê.'], 403);
        }

        ['data' => $data, 'warnings' => $warnings] = $intake->scanQrForAdmin($request);

        $age = CccdIdentity::ageOn($data);

        return response()->json([
            'scanned'       => $data !== null,
            'data'          => $data,
            'warnings'      => $warnings,
            'tenant_fields' => $data ? CccdScanMapper::toTenantApiFields($data) : null,
            'age'           => $age,
            'min_age'       => CccdScanMapper::TENANT_MIN_AGE,
            'under_age'     => $age !== null && $age < CccdScanMapper::TENANT_MIN_AGE,
        ]);
    }
}
