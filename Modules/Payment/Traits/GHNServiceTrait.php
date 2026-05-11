<?php

namespace Modules\Payment\Traits;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait GHNServiceTrait
{
    public function fetchProvincesGHN()
    {
        return cache()->remember('ghn_provinces', now()->addHours(24), function () {
            $response = Http::withHeaders(['token' => Config::get('ghn.api_token')])
                ->retry(3, 1000) // Retry nếu gặp lỗi
                ->timeout(30)
                ->get('https://online-gateway.ghn.vn/shiip/public-api/master-data/province');

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }

            Log::error('GHN API Error (fetchProvinces): ', $response->json());
            return [];
        });
    }

    public function fetchDistrictsGHN($provinceId)
    {
        $cacheKey = 'ghn_districts_' . $provinceId;
        return cache()->remember($cacheKey, now()->addHours(24), function () use ($provinceId) {
            $response = Http::withHeaders(['token' => Config::get('ghn.api_token')])
                ->retry(3, 1000)
                ->timeout(30)
                ->post('https://online-gateway.ghn.vn/shiip/public-api/master-data/district', [
                    'province_id' => (int) $provinceId,
                ]);

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }

            Log::error('GHN API Error (fetchDistricts): ', $response->json());
            return [];
        });
    }

    public function fetchWardsGHN($districtId)
    {
        $cacheKey = 'ghn_wards_' . $districtId;
        return cache()->remember($cacheKey, now()->addHours(24), function () use ($districtId) {
            $response = Http::withHeaders(['token' => Config::get('ghn.api_token')])
                ->retry(3, 1000)
                ->timeout(30)
                ->get('https://online-gateway.ghn.vn/shiip/public-api/master-data/ward', [
                    'district_id' => (int) $districtId,
                ]);

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }

            Log::error('GHN API Error (fetchWards): ', $response->json());
            return [];
        });
    }

    public function calculateShippingFee($params)
    {
        $serviceResponse = Http::withHeaders([
            'token' => Config::get('ghn.api_token'),
        ])->get('https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/available-services', [
            'shop_id' => Config::get('ghn.shop_id'),
            'from_district' => $params['from_district_id'],
            'to_district' => $params['to_district_id'],
        ]);

        if ($serviceResponse->successful() && !empty($serviceResponse->json()['data'])) {
            foreach ($serviceResponse->json()['data'] as $service) {
                $params['service_type_id'] = $service['service_type_id'];

                $response = Http::withHeaders([
                    'token' => Config::get('ghn.api_token'),
                    'shop_id' => Config::get('ghn.shop_id'),
                ])->retry(3, 1000)
                    ->timeout(30)
                    ->get('https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/fee', $params);

                if ($response->successful()) {
                    return $response->json()['data']['total'] ?? null;
                } else {
                    session()->flash('error', "Dịch vụ loại ID {$service['service_type_id']} thất bại: " . $response->json()['message']);
                }
            }
            return null;
        } else {
            session()->flash('error', 'Không thể lấy dịch vụ vận chuyển khả dụng.');
            return null;
        }
    }

    public static function postOrderToGHN($payload)
    {
        $response = Http::withHeaders([
            'Token' => Config::get('ghn.api_token'),
            'ShopId' => Config::get('ghn.shop_id'),
            'Content-Type' => 'application/json',
        ])->post('https://online-gateway.ghn.vn/shiip/public-api/v2/shipping-order/create', $payload);

        if ($response->successful() && isset($response->json()['data']['order_code'])) {
            return ['success' => true, 'data' => $response->json()['data']];
        }

        return ['success' => false, 'message' => $response->json()['message'] ?? 'Lỗi không xác định'];
    }
}

