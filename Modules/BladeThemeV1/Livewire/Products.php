<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;
use Carbon\Carbon;

class Products extends Component
{
    use HandleConfigTrait;
    public $style;
    public $configuredCategories = [];
    public $parentCategory;
    public $childCategories;

    // Search state
    public bool   $hasSearchParams    = false;
    public array  $searchSections     = [];
    public string $searchKey          = '';   // thay đổi mỗi lần search → wire:key tạo DOM mới
    public string $filterLocation     = '';
    public string $filterType         = '';
    public string $filterGuests       = '';
    public string $filterCheckIn      = '';
    public string $filterCheckOut     = '';
    public string $filterBuoi         = '';
    public string $filterProvinceName = '';
    public string $filterTypeName     = '';

    public function mount($config = null, $uniqueId = null)
    {
        $this->uniqueId = $uniqueId ?? uniqid('products_');

        // Chỉ xử lý config khi được truyền vào (gọi từ CMS loop)
        if ($config) {
            $this->setConfig($config);
            $listRoom = json_decode($config['list_room'] ?? '{}', true);
            $categoriesWithKeys = $listRoom['categories'] ?? [];
            $categoriesConfig = array_values($categoriesWithKeys);
            $tempChildCategories = collect();

            foreach ($categoriesConfig as $configItem) {
                $categoryId = $configItem['category_id'] ?? null;
                $productIds = $configItem['products'] ?? [];
                $grandChild = Category::with('parent')->find($categoryId);

                if (!$grandChild) continue;
                if ($grandChild->parent) {
                    $tempChildCategories->put($grandChild->parent->id, $grandChild->parent);
                }
                $this->configuredCategories[] = [
                    'category' => $grandChild,
                    'products' => !empty($productIds) ? $this->getRooms($productIds) : [],
                ];
            }

            $this->childCategories = $tempChildCategories;
            $firstChild = $this->childCategories->first();
            $this->parentCategory = ($firstChild && $firstChild->parent_id)
                ? Category::find($firstChild->parent_id)
                : null;
        }

        // Đọc URL query params
        $this->filterLocation = request()->query('location', '');
        $this->filterType     = request()->query('type', '');
        $this->filterGuests   = request()->query('guests', '');
        $this->filterCheckIn  = request()->query('check_in', '');
        $this->filterCheckOut = request()->query('check_out', '');
        $this->filterBuoi     = request()->query('buoi', '');

        $this->hasSearchParams = $this->filterLocation !== ''
            || $this->filterType     !== ''
            || $this->filterGuests   !== ''
            || $this->filterCheckIn  !== ''
            || $this->filterCheckOut !== ''
            || $this->filterBuoi     !== '';

        $this->loadFromSearch();
    }

    #[On('hero-search')]
    public function handleHeroSearch(
        string $location = '',
        string $type     = '',
        string $guests   = '',
        string $checkIn  = '',
        string $checkOut = '',
        string $buoi     = ''
    ): void {
        $this->filterLocation = $location;
        $this->filterType     = $type;
        $this->filterGuests   = $guests;
        $this->filterCheckIn  = $checkIn;
        $this->filterCheckOut = $checkOut;
        $this->filterBuoi     = $buoi;

        $this->hasSearchParams = $location !== ''
            || $type     !== ''
            || $guests   !== ''
            || $checkIn  !== ''
            || $checkOut !== ''
            || $buoi     !== '';

        // Luôn load (có hoặc không có filter)
        $this->loadFromSearch();

        // Báo JS biết Livewire đã re-render xong để scroll + init carousels
        $this->dispatch('products-updated');
    }

    private function loadFromSearch(): void
    {
        // Key thay đổi mỗi lần search → wire:key trên carousel tạo DOM mới hoàn toàn
        $this->searchKey = substr(md5(
            $this->filterType . '|' . $this->filterLocation . '|' .
            $this->filterGuests . '|' . $this->filterCheckIn . '|' . $this->filterCheckOut .
            '|' . microtime()
        ), 0, 8);

        // Resolve tên tỉnh / loại phòng để hiển thị
        $this->filterProvinceName = '';
        $this->filterTypeName     = '';

        if ($this->filterLocation) {
            $province = Province::where('slug', $this->filterLocation)->first();
            $this->filterProvinceName = $province?->name ?? $this->filterLocation;
        }

        if ($this->filterType) {
            $roomType = RoomType::where('slug', $this->filterType)->first();
            $this->filterTypeName = $roomType?->name ?? $this->filterType;
        }

        // --- Build base query ---
        $query = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->activeBranch()
            ->orderBy('sort_order');

        // Chỉ lấy sản phẩm có room type đang active (bỏ qua product chưa gán room type)
        $query->whereHas('roomType', fn($q) => $q->where('is_active', true));

        // Lọc theo loại phòng
        if ($this->filterType) {
            $query->whereHas('roomType', fn($q) => $q->where('slug', $this->filterType));
        }

        // Lọc theo tỉnh: province → province_branches → categories (branch + children)
        if ($this->filterLocation) {
            $province = Province::where('slug', $this->filterLocation)->first();
            if ($province) {
                $branchCatIds = $province->branches()
                    ->where('status', true)
                    ->pluck('categorie_id')
                    ->toArray();

                $childCatIds = Category::whereIn('parent_id', $branchCatIds)
                    ->pluck('id')
                    ->toArray();

                $allCatIds = array_merge($branchCatIds, $childCatIds);

                $query->whereHas('categories', fn($q) => $q->whereIn('categories.id', $allCatIds));
            }
        }

        // Lọc theo loại đặt phòng: 1 = theo giờ, 2 = theo ngày (cột styles)
        if ($this->filterBuoi !== '') {
            $query->where('styles', (int) $this->filterBuoi);
        }

        // Lọc số khách (nếu products có trường số khách thì thêm ở đây)
        // Hiện tại bỏ qua vì products chưa có trường guests

        // Loại bỏ phòng đã được đặt trùng với khung giờ tìm kiếm
        // Overlap: booking.checkin_date < filterCheckOut AND booking.checkout_date > filterCheckIn
        if ($this->filterCheckIn && $this->filterCheckOut) {
            $searchCheckIn  = $this->parseSearchDate($this->filterCheckIn);
            $searchCheckOut = $this->parseSearchDate($this->filterCheckOut);

            if ($searchCheckIn && $searchCheckOut && $searchCheckOut->gt($searchCheckIn)) {
                $query->whereDoesntHave('orderItems', function ($q) use ($searchCheckIn, $searchCheckOut) {
                    $q->whereNotNull('checkin_date')
                      ->whereNotNull('checkout_date')
                      ->where('checkin_date', '<', $searchCheckOut)
                      ->where('checkout_date', '>', $searchCheckIn)
                      ->whereHas('order', function ($oq) {
                          $oq->whereIn('status', ['pending', 'paid', 'deposit', 'confirmed']);
                      });
                });
            }
        }

        $productIds = $query->pluck('id')->toArray();

        if (empty($productIds)) {
            $this->searchSections = [];
            return;
        }

        // Lấy products đã map (reuse getRooms())
        $products = $this->getRooms($productIds);

        // Load categories kèm parent để hiển thị vị trí trên card
        $allCatIdList = $products->flatMap(function ($p) {
            return collect(explode(',', (string) ($p->cate_id ?? '')))->filter()->map('intval');
        })->unique()->values()->toArray();

        $catMap = Category::with('parent')
            ->whereIn('id', $allCatIdList)
            ->get()
            ->keyBy('id');

        // Gắn thông tin category vào từng product
        $products = $products->map(function ($product) use ($catMap) {
            $firstCatId = collect(explode(',', (string) ($product->cate_id ?? '')))
                ->filter()
                ->map('intval')
                ->first();

            $grandchild = $firstCatId ? $catMap->get($firstCatId) : null;
            $product->display_grandchild = $grandchild;
            $product->display_child      = $grandchild?->parent;
            return $product;
        });

        // Group by room type, theo sort_order của room type
        $roomTypes = RoomType::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        $grouped = $products->groupBy(fn($p) => $p->room_type_id ?? 0);

        $sections = [];
        $groupedRoomTypeIds = [];

        foreach ($roomTypes as $rtId => $rt) {
            $group = $grouped->get($rtId);
            if (!$group || $group->isEmpty()) {
                continue;
            }
            $groupedRoomTypeIds[] = $rtId;
            $sections[] = [
                'title'    => $rt->name,
                'slug'     => $rt->slug,
                'count'    => $group->count(),
                'products' => $group->values(),
            ];
        }

        // Products không có room_type_id hoặc room type không active → nhóm chung vào section "Phòng"
        $ungrouped = $products->filter(
            fn($p) => !$p->room_type_id || !in_array((int) $p->room_type_id, $groupedRoomTypeIds)
        );
        if ($ungrouped->isNotEmpty()) {
            $sections[] = [
                'title'    => 'Phòng',
                'slug'     => 'phong',
                'count'    => $ungrouped->count(),
                'products' => $ungrouped->values(),
            ];
        }

        $this->searchSections = $sections;
    }

public function getRooms($productIds)
{
    $products = Product::with([
        'categories',
        'tags',
        'media',
        'roomTimeSlots.timeSlot',
        'orderItems' => function ($query) {
            $query->where('checkout_date', '>', now())
                ->whereHas('order', fn($q) => $q->whereIn('status', ['pending', 'paid', 'confirmed']));
        },
        'orderItems.order',
    ])
        ->withCount('ratings')
        ->where('is_activated', 1)
        ->whereIn('id', $productIds)
        ->get()
        ->map(function ($product) {
            $product->pid = $product->id;
            $product->pname = $product->name;
            $product->pslug = $product->slug;
            $product->psd = $product->short_description;
            $product->cate_id = $product->categories->pluck('id')->join(',');
            $product->cate_name = $product->categories->pluck('name')->join(',');
            $product->cate_slug = $product->categories->pluck('slug')->join(',');

            // URL canonical /{type}/{location}/{branch}/{slug} khi xác định được loại hình + chi
            // nhánh (nối tiếp silo), rơi về /room/{slug}/ khi không (server tự 301 sang canonical
            // nếu có — xem BladeThemeV1Controller::renderProductDetail()).
            $loc = BranchBookConfig::resolveLocationForProduct($product);
            $product->room_url = $loc
                ? '/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $product->slug . '/'
                : '/room/' . $product->slug . '/';
            $product->tag_name = $product->tags->map(fn($tag) => $tag->getTranslation('name', 'vi'))->join(',');
            $product->tag_image = $product->tags->map(fn($tag) => $tag->getTranslation('image', 'vi'))->join(',');
            $product->media_file = $product->media_file;
            
            $product->time_price_pairs = $product->roomTimeSlots
                ->map(function ($slot) {
                    if (!$slot->timeSlot || empty($slot->timeSlot->start_time) || empty($slot->timeSlot->end_time)) {
                        return null;
                    }
                    try {
                        $startTime = Carbon::parse($slot->timeSlot->start_time);
                        $endTime = Carbon::parse($slot->timeSlot->end_time);
                        
                        // Tính tổng số phút chênh lệch
                        $totalMinutes = $startTime->diffInMinutes($endTime);
                        
                        // Tính giờ và phút
                        $hours = floor($totalMinutes / 60);
                        $minutes = $totalMinutes % 60;
                        
                        // Format: "giờ.phút" để dễ xử lý ở blade
                        $timeValue = $hours + ($minutes / 100);
                        
                        return "{$timeValue}:{$slot->price}";

                    } catch (\Exception) {
                        return null;
                    }
                })
                ->filter()
                ->join(',');
            
            // Chỉ thêm giá cả ngày nếu có full_booking_discount
            $fullBookingDiscount = $product->full_booking_discount;
            if (!empty($fullBookingDiscount) && $product->time_price_pairs) {
                // Tính tổng giá gốc của tất cả khung giờ
                $totalOriginalPrice = $product->roomTimeSlots->sum('price');
                
                // Kiểm tra xem là giảm theo % hay giảm cố định
                $discountValue = trim($fullBookingDiscount);
                
                if (str_contains($discountValue, '%')) {
                    // Giảm theo phần trăm
                    $percentValue = floatval(str_replace('%', '', $discountValue));
                    if ($percentValue > 0 && $percentValue < 100) {
                        $fullDayPrice = $totalOriginalPrice * (1 - ($percentValue / 100));
                    } else {
                        $fullDayPrice = $totalOriginalPrice;
                    }
                } else {
                    // Giảm cố định (số tiền)
                    $fixedDiscount = floatval(str_replace([',', '.'], '', $discountValue));
                    $fullDayPrice = max(0, $totalOriginalPrice - $fixedDiscount);
                }
                
                // Thêm giá cả ngày vào cuối chuỗi với timeValue = 999
                $product->time_price_pairs .= ",999:{$fullDayPrice}";
            }
            
            return $product;
        });
    return $products->unique('pid')->values();
}

    /**
     * Parse chuỗi ngày từ hero section (format "DD/MM/YYYY HH:MM" hoặc ISO)
     * Carbon::parse() không nhận DD/MM/YYYY nên phải thử tuần tự các format.
     */
    private function parseSearchDate(string $value): ?Carbon
    {
        $formats = ['d/m/Y H:i', 'd/m/Y G:i', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value));
            } catch (\Exception) {
                // thử format tiếp theo
            }
        }
        return null;
    }

    public function render()
    {
        return view('bladethemev1::livewire.products');
    }
}