<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\GuestCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class GuestCustomerController extends Controller
{
    /**
     * POST /api/guest/province
     * Lưu khu vực đã chọn cho khách vãng lai.
     * Nếu device_token chưa tồn tại → tạo record mới.
     * Nếu đã tồn tại → cập nhật province_id.
     */
    public function saveProvince(Request $request): JsonResponse
    {
        $request->validate([
            'device_token' => 'required|string|max:255',
            'province_id'  => 'required|integer|exists:provinces,id',
        ]);

        $guest = GuestCustomer::findOrCreateByDevice(
            deviceToken: $request->device_token,
            attributes: ['province_id' => $request->province_id],
        );

        return response()->json([
            'guest_customer_id' => $guest->id,
            'province_id'       => $guest->province_id,
        ]);
    }
}
