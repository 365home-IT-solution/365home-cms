<?php

namespace Modules\BladeThemeV1\Traits;

trait AddressHandlerTrait
{
    public function updatedProvinceId()
    {
        if ($this->provinceId) {
            $this->reset(['districtId', 'wardId', 'wards']);
            $this->fetchDistricts();
        }
    }

    public function updatedDistrictId($value)
    {
        if ($value) {
            $this->wardId = null;
            $this->fetchWards($value);
            $this->getShippingFee();
        }
    }

    public function updatedWardId()
    {
        $this->getShippingFee();
    }

    public function fetchProvinces()
    {
        $this->provinces = $this->shippingMethod === 'GHN'
            ? $this->fetchProvincesGHN()
            : $this->fetchProvincesGHN();
    }

    public function fetchDistricts()
    {
        $this->districts = $this->shippingMethod === 'GHN'
            ? $this->fetchDistrictsGHN($this->provinceId)
            : $this->fetchDistrictsGHN($this->provinceId);
    }

    public function fetchWards($districtId)
    {
        $this->wards = $this->shippingMethod === 'GHN'
            ? $this->fetchWardsGHN($districtId)
            : $this->fetchWardsGHN($districtId);
    }
}
