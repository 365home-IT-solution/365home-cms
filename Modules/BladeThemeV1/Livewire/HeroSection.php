<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Livewire\Component;
use Modules\Product\App\Models\RoomType;

class HeroSection extends Component
{
    public $locations = [];
    public $roomTypes = [];

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
