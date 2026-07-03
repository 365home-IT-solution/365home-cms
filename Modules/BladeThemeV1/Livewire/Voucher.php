<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Settings\GeneralSettings;
use Livewire\Component;

class Voucher extends Component
{
    public string $logo = '';
    public array $vouchers = [];

    /**
     * Dữ liệu ảo tạm thời — thay bằng nguồn thật khi có (DB/CMS).
     */
    public function mount(): void
    {
        $this->logo = (new GeneralSettings())->brand_logo;

        $this->vouchers = [
            [
                'label'       => 'Ưu đãi giờ vàng',
                'timeRange'   => 'Áp dụng 10:00 – 16:00 hằng ngày',
                'title'       => 'Giảm 30%',
                'description' => 'Khi thuê phòng theo giờ',
                'code'        => 'GIOVANG30',
                'note'        => 'Số lượng ưu đãi có hạn mỗi ngày',
                'emoji'       => '☀️',
                'bg'          => 'linear-gradient(135deg,#e0785a,#c2432e)',
            ],
            [
                'label'       => 'Ưu đãi giờ khuya',
                'timeRange'   => 'Áp dụng 22:00 – 06:00 hằng ngày',
                'title'       => 'Giảm 20%',
                'description' => 'Khi thuê phòng theo giờ',
                'code'        => 'GIOKHUYA20',
                'note'        => 'Số lượng ưu đãi có hạn mỗi ngày',
                'emoji'       => '🌙',
                'bg'          => 'linear-gradient(135deg,#3a3970,#20204a)',
            ],
            [
                'label'       => 'Quà tặng kèm',
                'timeRange'   => 'Khi đặt phòng từ 3 giờ trở lên',
                'title'       => 'Tặng bánh ngọt',
                'description' => 'Cho mỗi lượt đặt phòng',
                'code'        => 'FREECAKE',
                'note'        => 'Số lượng ưu đãi có hạn mỗi ngày',
                'emoji'       => '🎂',
                'bg'          => 'linear-gradient(135deg,#d8619a,#a8356f)',
            ],
            [
                'label'       => 'Quà tặng kèm',
                'timeRange'   => 'Khi đặt phòng buổi tối',
                'title'       => 'Tặng nước ngọt',
                'description' => 'Cho mỗi lượt đặt phòng',
                'code'        => 'FREEDRINK',
                'note'        => 'Số lượng ưu đãi có hạn mỗi ngày',
                'emoji'       => '🥤',
                'bg'          => 'linear-gradient(135deg,#3f9d6e,#1f6e46)',
            ],
            [
                'label'       => 'Combo đặc biệt',
                'timeRange'   => 'Giờ vàng 10:00 – 16:00',
                'title'       => 'Giảm 25%',
                'description' => 'Tặng bánh & nước ngọt',
                'code'        => 'COMBO25',
                'note'        => 'Số lượng ưu đãi có hạn mỗi ngày',
                'emoji'       => '🎉',
                'bg'          => 'linear-gradient(135deg,#c08a3e,#8a5e22)',
            ],
        ];
    }

    public function render()
    {
        return view('bladethemev1::livewire.voucher');
    }
}
