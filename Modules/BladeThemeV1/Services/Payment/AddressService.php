<?php

namespace Modules\BladeThemeV1\Services\Payment;

class AddressService
{
    public function getFullAddress($provinces, $districts, $wards, $provinceId, $districtId, $wardId, $address)
    {
        $provinceName = collect($provinces)->firstWhere('ProvinceID', $provinceId)['ProvinceName'] ?? '';
        $districtName = collect($districts)->firstWhere('DistrictID', $districtId)['DistrictName'] ?? '';
        $wardName = collect($wards)->firstWhere('WardCode', $wardId)['WardName'] ?? '';

        return [
            'provinceName' => $provinceName,
            'districtName' => $districtName,
            'wardName' => $wardName,
            'fullAddress' => "{$address}, {$wardName}, {$districtName}, {$provinceName}"
        ];
    }
}
