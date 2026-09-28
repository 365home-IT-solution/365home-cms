<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Models\VehicleRate;

// Bảng giá gửi xe THEO TOÀ NHÀ — bản API của ManageVehicleRates (Filament). Cần quyền
// "page_manage_vehicle_rates" (hoặc super_admin) và chỉ trên Toà nhà được phân quyền.
class VehicleRateController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/vehicle-rates?building_id=
    public function show(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'page_manage_vehicle_rates')) {
            return response()->json(['message' => 'Không có quyền xem bảng giá gửi xe.'], 403);
        }

        $buildingId = $request->integer('building_id');

        if (! $buildingId || ! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Thiếu building_id hoặc không có quyền trên Toà nhà này.'], 422);
        }

        return response()->json(['data' => $this->transform($buildingId)]);
    }

    // PUT/PATCH /api/admin/minihouse/vehicle-rates
    // { building_id, rates: { motorbike: { monthly_fee, max_per_contract, capacity }, car: {...} } }
    // Loại xe không gửi lên = giữ nguyên. max_per_contract/capacity = null → không giới hạn.
    public function update(Request $request): JsonResponse
    {
        if (! $this->hasPermission($request, 'page_manage_vehicle_rates')) {
            return response()->json(['message' => 'Không có quyền sửa bảng giá gửi xe.'], 403);
        }

        $data = $request->validate([
            'building_id'                => 'required|integer',
            'rates'                      => 'required|array',
            'rates.*.monthly_fee'        => 'nullable|numeric|min:0',
            'rates.*.max_per_contract'   => 'nullable|integer|min:0',
            'rates.*.capacity'           => 'nullable|integer|min:0',
        ]);

        $buildingId = (int) $data['building_id'];

        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return response()->json(['message' => 'Không có quyền cấu hình toà nhà này.'], 403);
        }

        foreach ($data['rates'] as $type => $row) {
            if (! array_key_exists($type, Vehicle::TYPES)) {
                return response()->json(['message' => "Loại xe không hợp lệ: {$type}."], 422);
            }

            $rate = VehicleRate::query()->firstOrNew(['building_id' => $buildingId, 'vehicle_type' => $type]);

            if (array_key_exists('monthly_fee', $row)) {
                $rate->monthly_fee = (float) ($row['monthly_fee'] ?? 0);
            }
            if (array_key_exists('max_per_contract', $row)) {
                $rate->max_per_contract = $row['max_per_contract'];
            }
            if (array_key_exists('capacity', $row)) {
                $rate->capacity = $row['capacity'];
            }

            $rate->save();
        }

        return response()->json(['data' => $this->transform($buildingId)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function transform(int $buildingId): array
    {
        $rates = VehicleRate::query()->where('building_id', $buildingId)->get()->keyBy('vehicle_type');
        $rows  = [];

        foreach (Vehicle::TYPES as $type => $label) {
            $rate   = $rates->get($type);
            $rows[] = [
                'vehicle_type'       => $type,
                'vehicle_type_label' => $label,
                'monthly_fee'        => (float) ($rate?->monthly_fee ?? 0),
                'max_per_contract'   => $rate?->max_per_contract,
                'capacity'           => $rate?->capacity,
            ];
        }

        return $rows;
    }
}
