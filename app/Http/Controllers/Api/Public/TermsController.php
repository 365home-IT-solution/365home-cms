<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\TermsVersion;
use App\Services\TermsService;
use Illuminate\Http\JsonResponse;

// Điều khoản dịch vụ HIỆU LỰC hiện tại để hiển thị trước khi khách tick đồng ý khi đăng ký.
class TermsController extends Controller
{
    private const SLUGS = ['homestay' => TermsVersion::TYPE_HOMESTAY, 'minihouse' => TermsVersion::TYPE_MINIHOUSE];

    // GET /api/public/terms/{slug}  (slug: homestay | minihouse)
    public function current(string $slug, TermsService $terms): JsonResponse
    {
        abort_unless(isset(self::SLUGS[$slug]), 404, 'Loại Điều khoản không tồn tại (homestay | minihouse).');
        $version = $terms->current(self::SLUGS[$slug]);
        abort_unless($version, 404, 'Chưa có Điều khoản dịch vụ.');

        return response()->json(['data' => $version->toApi() + ['required' => $terms->required($version->type)]]);
    }
}
