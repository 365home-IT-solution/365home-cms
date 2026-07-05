<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use App\Settings\GeneralSettings;
use Livewire\Component;
use Modules\BladeThemeV1\Enums\HeaderSection;
use Modules\BladeThemeV1\Traits\HandleSectionCfgTrait;
use Modules\Product\App\Models\RoomType;
use Modules\ThemeSetting\App\Models\ThemeSection;

class HeroSection extends Component
{
    use HandleSectionCfgTrait;

    private const DEFAULT_LOGO_HEIGHT = '42';

    public $locations = [];
    public $roomTypes = [];
    public array $navLinks = [];
    public string $logo = '';
    public string $logoHeight = self::DEFAULT_LOGO_HEIGHT;

    private ?ThemeSection $section = null;

    public bool   $noBanner        = false;
    public bool   $headerRow       = false;
    public bool   $mobileHeaderModal = false;

    public string $selectedLocation = '';
    public string $selectedRoomType = 'all';
    // '1' = Theo giờ (1 ngày + giờ nhận/trả), '2' = Theo ngày (khoảng ngày, giờ cố định 14:00/12:00)
    public string $selectedBuoi = '1';
    public string $selectedGuests = '';
    public string $checkIn  = '';
    public string $checkOut = '';

    public function mount(): void
    {
        $this->logo = (new GeneralSettings())->brand_logo;
        $this->logoHeight = $this->getHeaderLogoHeight();

        $this->roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'icon_url'])
            ->toArray();

        $this->loadLocations();

        $this->navLinks = [
            [
                'label' => 'Trang chủ',
                'url'   => url('/'),
                'icon'  => '<svg style="width:16px;height:16px;color:#6b7280;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',
            ],
            [
                'label' => 'Tìm phòng',
                'url'   => route('product.search'),
                'icon'  => '<svg style="width:16px;height:16px;color:#6b7280;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
            ],
            [
                'label' => 'Tra cứu booking',
                'url'   => url('/ticket-booking'),
                'icon'  => '<svg style="width:16px;height:16px;color:#6b7280;flex-shrink:0;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>',
            ],
        ];

        // // Cũ: lấy từ Category
        // $this->roomTypes = Category::where('status', 1)
        //     ->where('category_type', 'product')
        //     ->whereNull('parent_id')
        //     ->orderBy('name')
        //     ->get(['id', 'name', 'slug'])
        //     ->toArray();
        // $this->locations = Category::where('status', 1)
        //     ->where('category_type', 'product')
        //     ->whereNotNull('parent_id')
        //     ->whereHas('parent', fn($q) => $q->whereNull('parent_id'))
        //     ->orderBy('name')
        //     ->get(['id', 'name', 'slug'])
        //     ->toArray();
    }

    public function setLocation(string $slug): void
    {
        $this->selectedLocation = $slug;
    }

    public function setBuoi(string $val): void
    {
        $this->selectedBuoi = $val;
    }

    public function setGuests(string $val): void
    {
        $this->selectedGuests = $val;
    }

    public function clearAll(): void
    {
        $this->selectedLocation = '';
        $this->selectedBuoi     = '1';
        $this->selectedGuests   = '';
        $this->checkIn          = '';
        $this->checkOut         = '';
    }

    public function setRoomType(string $slug): void
    {
        $this->selectedRoomType = $slug;
        $this->loadLocations($slug === 'all' ? null : $slug);
    }

    public function updatedSelectedRoomType(string $value): void
    {
        $this->loadLocations($value === 'all' ? null : $value);
    }

    private function getHeaderLogoHeight(): string
    {
        $this->section = ThemeSection::where('name', 'header')->with(['children'])->first();

        if (! $this->section) {
            return self::DEFAULT_LOGO_HEIGHT;
        }

        $logoConfig = $this->getChildSectionConfigs(HeaderSection::LOGO->value);

        return (string) ($logoConfig['height'] ?? self::DEFAULT_LOGO_HEIGHT);
    }

    private function loadLocations(?string $roomTypeSlug = null): void
    {
        if (!$roomTypeSlug) {
            $this->locations = Province::whereHas('branches', fn($q) => $q->where('status', true))
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'lat', 'lng'])
                ->toArray();
            return;
        }

        // Chỉ lấy tỉnh nào có sản phẩm thuộc loại phòng đã chọn,
        // duyệt qua: province_branches → category (branch) → products (hoặc qua children categories)
        $this->locations = Province::whereHas('branches', function ($q) use ($roomTypeSlug) {
            $q->where('status', true)
              ->whereHas('category', function ($catQ) use ($roomTypeSlug) {
                  $catQ->where(function ($inner) use ($roomTypeSlug) {
                      // Sản phẩm gắn thẳng vào category branch
                      $inner->whereHas('products', function ($p) use ($roomTypeSlug) {
                          $p->whereHas('roomType', fn($r) => $r->where('slug', $roomTypeSlug))
                            ->where('is_activated', true)
                            ->where('is_in_stock', true);
                      })
                      // Hoặc sản phẩm gắn vào category con của branch đó
                      ->orWhereHas('children', function ($childQ) use ($roomTypeSlug) {
                          $childQ->whereHas('products', function ($p) use ($roomTypeSlug) {
                              $p->whereHas('roomType', fn($r) => $r->where('slug', $roomTypeSlug))
                                ->where('is_activated', true)
                                ->where('is_in_stock', true);
                          });
                      });
                  });
              });
        })
        ->orderBy('name')
        ->get(['id', 'name', 'slug', 'lat', 'lng'])
        ->toArray();

        // Reset tỉnh đang chọn nếu không còn trong danh sách lọc
        if ($this->selectedLocation) {
            $available = array_column($this->locations, 'slug');
            if (!in_array($this->selectedLocation, $available, true)) {
                $this->selectedLocation = '';
            }
        }
    }

    public function search(): void
    {
        // location đi vào path (/s/{location}, kiểu Airbnb), phần còn lại vẫn ở
        // query string với tên gần với Airbnb hơn (checkin/checkout/adults thay vì check_in/
        // check_out/guests); type/buoi là khái niệm riêng của mình, không có tương đương ở Airbnb
        // nên giữ nguyên tên.
        $params = array_filter([
            'type'     => $this->selectedRoomType !== 'all' ? $this->selectedRoomType : '',
            'adults'   => $this->selectedGuests,
            'checkin'  => $this->checkIn,
            'checkout' => $this->checkOut,
            'buoi'     => $this->selectedBuoi,
        ]);

        $path = route('product.search', $this->selectedLocation ? ['location' => $this->selectedLocation] : []);
        $this->redirect($path . (count($params) ? '?' . http_build_query($params) : ''), navigate: true);
    }

    public function render()
    {
        return view('bladethemev1::livewire.hero-section');
    }
}
