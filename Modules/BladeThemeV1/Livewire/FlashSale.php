<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;

class FlashSale extends Component
{
    // protected (không public): dữ liệu render sẵn phía server cho LẦN TẢI ĐẦU — public thì Livewire
    // nhét cả mảng vào wire:snapshot (~7KB HTML trang chủ, kéo tỉ lệ text/HTML xuống). Component này
    // không có action/listener nào nên không bao giờ render lại qua Livewire; nếu có, mảng rỗng và
    // view tự lùi về nhánh <template x-if> lấy dữ liệu từ /api/v1/home như trước khi có criticalHome.
    protected array $criticalHome = [];

    public function mount(array $criticalHome = []): void
    {
        $this->criticalHome = $criticalHome;
    }

    public function render()
    {
        return view('bladethemev1::livewire.flash-sale', [
            'criticalHome' => $this->criticalHome,
        ]);
    }
}
