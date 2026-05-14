<?php

namespace Modules\BladeThemeV1\Services\Payment;

class ShippingService
{
    public function calculateShippingFee($method, $params)
    {
        if ($method === 'GHN') {
            return $this->calculateGHNFee($params);
        } elseif ($method === 'GHTK') {
            return $this->calculateGHTKFee($params);
        }
        return null;
    }

    private function calculateGHNFee($params)
    {
        // Implementation of GHN fee calculation
    }

    private function calculateGHTKFee($params)
    {
        // Implementation of GHTK fee calculation
    }

    public function prepareGHNParams($data)
    {
        return [
            'insurance_value' => 500000,
            'from_district_id' => 1542,
            'to_district_id' => $data['districtId'],
            'to_ward_code' => $data['wardId'],
            'weight' => 1000,
            'length' => 15,
            'width' => 15,
            'height' => 15,
        ];
    }

    public function prepareGHTKParams($data)
    {
        return [
            'pick_address_id' => $data['pickAddressId'],
            'pick_address' => $data['pickAddress'],
            'pick_province' => $data['pickProvince'],
            'pick_district' => $data['pickDistrict'],
            'pick_ward' => $data['pickWard'] ?? '',
            'province' => $data['provinceName'],
            'district' => $data['districtName'],
            'ward' => $data['wardName'] ?? '',
            'street' => $data['receiverStreet'] ?? '',
            'weight' => 1000,
            'deliver_option' => 'xfast',
        ];
    }
}
