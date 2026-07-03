<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceCoordinatesSeeder extends Seeder
{
    /**
     * Toạ độ trung tâm (thành phố/thị xã trung tâm) của 34 tỉnh/thành — dùng để tính
     * tỉnh/thành gần nhất theo vị trí trình duyệt (tính năng "Vị trí của tôi").
     */
    public function run(): void
    {
        $coordinates = [
            'ha-noi'       => [21.0285, 105.8542],
            'ho-chi-minh'  => [10.8231, 106.6297],
            'da-nang'      => [16.0544, 108.2022],
            'hai-phong'    => [20.8449, 106.6881],
            'can-tho'      => [10.0452, 105.7469],
            'hue'          => [16.4637, 107.5909],
            'an-giang'     => [10.3860, 105.4351],
            'bac-giang'    => [21.2731, 106.1946],
            'bac-ninh'     => [21.1861, 106.0763],
            'ben-tre'      => [10.2433, 106.3756],
            'binh-dinh'    => [13.7757, 109.2219],
            'binh-thuan'   => [10.9804, 108.2621],
            'ca-mau'       => [9.1769, 105.1524],
            'cao-bang'     => [22.6666, 106.2639],
            'dak-lak'      => [12.7100, 108.2378],
            'dien-bien'    => [21.3856, 103.0169],
            'dong-nai'     => [10.9450, 106.8241],
            'dong-thap'    => [10.4938, 105.6881],
            'gia-lai'      => [13.9833, 108.0000],
            'ha-giang'     => [22.8025, 104.9784],
            'ha-nam'       => [20.5835, 105.9230],
            'ha-tinh'      => [18.3560, 105.8877],
            'khanh-hoa'    => [12.2388, 109.1967],
            'lao-cai'      => [22.4809, 103.9755],
            'nghe-an'      => [18.6796, 105.6813],
            'ninh-binh'    => [20.2506, 105.9744],
            'phu-tho'      => [21.3227, 105.4020],
            'quang-ngai'   => [15.1214, 108.8044],
            'quang-ninh'   => [20.9599, 107.0416],
            'son-la'       => [21.3256, 103.9188],
            'tay-ninh'     => [11.3100, 106.0989],
            'thai-binh'    => [20.4463, 106.3365],
            'thanh-hoa'    => [19.8067, 105.7852],
            'tuyen-quang'  => [21.8237, 105.2280],
        ];

        foreach ($coordinates as $slug => [$lat, $lng]) {
            Province::where('slug', $slug)->update(['lat' => $lat, 'lng' => $lng]);
        }
    }
}
