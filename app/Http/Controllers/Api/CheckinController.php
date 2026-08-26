<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\CustomerCheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CheckinController extends Controller
{
    public function __construct(private readonly CustomerCheckinService $checkins) {}

    /**
     * GET /api/checkin
     *
     * Lịch điểm danh chu kỳ hiện tại của khách — dùng để hiển thị popup khi mở app.
     */
    public function calendar(Request $request): JsonResponse
    {
        return response()->json([
            'checkin' => $this->checkins->calendar($request->user()),
        ]);
    }

    /**
     * POST /api/checkin
     *
     * Điểm danh hôm nay. Idempotent — gọi lại trong cùng ngày không tick thêm.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'checkin' => $this->checkins->checkin($request->user()),
        ]);
    }
}
