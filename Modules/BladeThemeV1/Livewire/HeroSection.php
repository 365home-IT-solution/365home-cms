<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Livewire\Component;
use Modules\Product\App\Models\RoomType;

class HeroSection extends Component
{
    public $locations = [];
    public $roomTypes = [];
    public array $navLinks = [];

    public bool   $noBanner        = false;

    public string $selectedLocation = '';
    public string $selectedRoomType = 'all';
    public string $selectedBuoi = '';
    public string $selectedGuests = '';
    public string $checkIn  = '';
    public string $checkOut = '';

    // Giờ / phút — quản lý bởi Livewire để PHP render disabled options chính xác
    public int  $checkInHour  = 14;
    public int  $checkInMin   = 0;
    public int  $checkOutHour = 16;
    public int  $checkOutMin  = 0;
    public bool $isSameDay    = true;

    public function mount(): void
    {
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

    // Khi checkInHour thay đổi → tự correct checkOutHour nếu cùng ngày
    public function updatedCheckInHour(): void
    {
        $this->correctCheckOut();
    }

    // Khi checkInMin thay đổi → tự correct checkOutMin nếu cùng giờ
    public function updatedCheckInMin(): void
    {
        $this->correctCheckOut();
    }

    // Alpine gọi khi người dùng chọn ngày (isSameDay có thể thay đổi)
    public function setIsSameDay(bool $value): void
    {
        $this->isSameDay = $value;
        if ($value) {
            $this->correctCheckOut();
        }
    }

    private function correctCheckOut(): void
    {
        if (! $this->isSameDay) return;

        $minutes = [0, 15, 30, 45];

        if ($this->checkOutHour < $this->checkInHour) {
            $this->checkOutHour = $this->checkInHour;
            $next = collect($minutes)->first(fn ($m) => $m > $this->checkInMin);
            if ($next !== null) {
                $this->checkOutMin = $next;
            } else {
                $this->checkOutHour = min($this->checkInHour + 1, 23);
                $this->checkOutMin  = 0;
            }
        } elseif ($this->checkOutHour === $this->checkInHour && $this->checkOutMin <= $this->checkInMin) {
            $next = collect($minutes)->first(fn ($m) => $m > $this->checkInMin);
            if ($next !== null) {
                $this->checkOutMin = $next;
            } else {
                $this->checkOutHour = min($this->checkInHour + 1, 23);
                $this->checkOutMin  = 0;
            }
        }
    }

    public function updatedSelectedRoomType(string $value): void
    {
        $this->loadLocations($value === 'all' ? null : $value);
    }

    private function loadLocations(?string $roomTypeSlug = null): void
    {
        if (!$roomTypeSlug) {
            $this->locations = Province::whereHas('branches', fn($q) => $q->where('status', true))
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
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
        ->get(['id', 'name', 'slug'])
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
        $params = array_filter([
            'location'  => $this->selectedLocation,
            'type'      => $this->selectedRoomType !== 'all' ? $this->selectedRoomType : '',
            'guests'    => $this->selectedGuests,
            'check_in'  => $this->checkIn,
            'check_out' => $this->checkOut,
            'buoi'      => $this->selectedBuoi,
        ]);

        $this->redirect(route('product.search') . (count($params) ? '?' . http_build_query($params) : ''), navigate: true);
    }

    public function render()
    {
        return view('bladethemev1::livewire.hero-section');
    }
}
