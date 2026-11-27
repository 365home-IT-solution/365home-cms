<?php

namespace Modules\Payment\Http\Controllers;

use App\Services\Payment\PlatformCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\BladeThemeV1\Support\QrCodeGenerator;

// Trang QR chuyển khoản Pay2S cho các khoản 365 thu hộ ngoài đơn phòng (gói dịch vụ, ký quỹ, đối soát, thuê xe, hoá đơn MiniHouse) — thay cho trang checkout của PayOS:
// hiện QR + thông tin chuyển khoản, tự hỏi trạng thái và báo thành công khi webhook Pay2S đã ghi nhận. Chỉ hiện thông tin chuyển khoản (không có dữ liệu cá nhân).
class PlatformCollectionController extends Controller
{
    public function show(string $kind, string $code, PlatformCollectionService $collection)
    {
        $data = $collection->find($kind, $code) ?? abort(404);

        return view('payment::platform-collect', [
            'collect' => $data,
            'qrImage' => filled($data['qr_code']) ? QrCodeGenerator::dataUri($data['qr_code'], 300) : null,
            'statusUrl' => route('platform-collect.status', ['kind' => $kind, 'code' => $code]),
        ]);
    }

    public function status(string $kind, string $code, PlatformCollectionService $collection): JsonResponse
    {
        $data = $collection->find($kind, $code) ?? abort(404);

        return response()->json(['data' => ['status' => $data['status']]]);
    }
}
