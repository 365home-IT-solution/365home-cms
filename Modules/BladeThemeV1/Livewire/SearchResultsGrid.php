<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Models\Province;
use Livewire\Component;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;
use Carbon\Carbon;

class SearchResultsGrid extends Component
{
    public string $filterLocation     = '';
    public string $filterProvinceName = '';
    public string $filterType         = '';
    public string $filterTypeName     = '';
    public string $filterBuoi         = ''; // '1' = theo giờ, '2' = theo ngày
    public string $filterCheckIn      = '';
    public string $filterCheckOut     = '';

    public array $branches   = [];
    public array $groups     = [];
    public int   $totalFound = 0;

    // IDs phòng đã bị đặt trong khoảng thời gian tìm kiếm
    private array $bookedIds = [];

    public function mount(): void
    {
        $this->filterLocation = request()->query('location', '');
        $this->filterType     = request()->query('type', '');
        $this->filterBuoi     = request()->query('buoi', '');
        $this->filterCheckIn  = request()->query('check_in', '');
        $this->filterCheckOut = request()->query('check_out', '');

        if ($this->filterType && $this->filterType !== 'all') {
            $this->filterTypeName = RoomType::where('slug', $this->filterType)->value('name') ?? '';
        }

        $this->bookedIds = $this->resolveBookedIds();
        $this->loadBranches();
    }

    // ── Lấy danh sách product_id đã bị đặt trong khung giờ tìm kiếm ──────────
    private function resolveBookedIds(): array
    {
        if (!$this->filterCheckIn || !$this->filterCheckOut) return [];

        try {
            // Format từ hero section: dd/MM/yyyy HH:mm
            $checkIn  = Carbon::createFromFormat('d/m/Y H:i', $this->filterCheckIn);
            $checkOut = Carbon::createFromFormat('d/m/Y H:i', $this->filterCheckOut);
        } catch (\Exception) {
            return [];
        }

        if ($checkOut->lte($checkIn)) return [];

        // Overlap condition: checkin_date < $checkOut AND checkout_date > $checkIn
        return OrderItem::whereHas('order', fn ($q) => $q->whereIn('status', ['paid', 'deposit', 'shipped', 'confirmed']))
            ->whereNotNull('product_id')
            ->whereNotNull('checkin_date')
            ->whereNotNull('checkout_date')
            ->where('checkin_date', '<', $checkOut)
            ->where('checkout_date', '>', $checkIn)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    // ── Load branches ──────────────────────────────────────────────────────────
    private function loadBranches(): void
    {
        $this->branches  = [];
        $this->groups    = [];
        $this->totalFound = 0;

        if ($this->filterLocation) {
            $province = Province::where('slug', $this->filterLocation)->first();
            if (!$province) return;

            $this->filterProvinceName = $province->name;
            $provinceBranches = $province->branches()->where('status', true)->get();
            if ($provinceBranches->isEmpty()) return;

            $branches = $this->buildBranchesFromPivot($provinceBranches);
            if (!empty($branches)) {
                $this->groups[]  = ['province_name' => $province->name, 'province_slug' => $province->slug, 'branches' => $branches];
                $this->branches  = $branches;
            }
        } else {
            $provinces = Province::whereHas('branches', fn ($q) => $q->where('status', true))
                ->with(['branches' => fn ($q) => $q->where('status', true)])
                ->orderBy('name')
                ->get();

            $allBranchCatIds = [];
            foreach ($provinces as $prov) {
                foreach ($prov->branches as $pb) {
                    if ($pb->categorie_id) $allBranchCatIds[] = $pb->categorie_id;
                }
            }
            $allBranchCatIds = array_unique($allBranchCatIds);
            if (empty($allBranchCatIds)) return;

            $childCats     = Category::whereIn('parent_id', $allBranchCatIds)->get(['id', 'parent_id']);
            $childCatIds   = $childCats->pluck('id')->toArray();
            $childToBranch = $childCats->pluck('parent_id', 'id')->toArray();
            $allCatIds     = array_merge($allBranchCatIds, $childCatIds);
            $branchCats    = Category::whereIn('id', $allBranchCatIds)->get(['id', 'name', 'slug'])->keyBy('id');
            $byBranch      = $this->fetchProductsByBranch($allCatIds, $allBranchCatIds, $childToBranch);

            foreach ($provinces as $prov) {
                $provinceBranchList = [];
                foreach ($prov->branches as $pb) {
                    $catId = $pb->categorie_id;
                    if (!$catId || !$branchCats->has($catId)) continue;
                    $branchProducts = $byBranch[$catId] ?? [];
                    if (empty($branchProducts)) continue;
                    $b = $this->makeBranch($catId, $branchCats->get($catId), $branchProducts);
                    if ($b) $provinceBranchList[] = $b;
                }
                if (empty($provinceBranchList)) continue;
                $this->groups[] = ['province_name' => $prov->name, 'province_slug' => $prov->slug, 'branches' => $provinceBranchList];
                foreach ($provinceBranchList as $b) $this->branches[] = $b;
            }
        }

        $this->totalFound = count($this->branches);
    }

    // ── Build branches từ pivot 1 tỉnh ────────────────────────────────────────
    private function buildBranchesFromPivot(\Illuminate\Database\Eloquent\Collection $provinceBranches): array
    {
        $branchCatIds  = $provinceBranches->pluck('categorie_id')->filter()->values()->toArray();
        $childCats     = Category::whereIn('parent_id', $branchCatIds)->get(['id', 'parent_id']);
        $childCatIds   = $childCats->pluck('id')->toArray();
        $childToBranch = $childCats->pluck('parent_id', 'id')->toArray();
        $allCatIds     = array_merge($branchCatIds, $childCatIds);
        $branchCats    = Category::whereIn('id', $branchCatIds)->get(['id', 'name', 'slug'])->keyBy('id');
        $byBranch      = $this->fetchProductsByBranch($allCatIds, $branchCatIds, $childToBranch);

        $result = [];
        foreach ($provinceBranches as $pb) {
            $catId = $pb->categorie_id;
            if (!$catId || !$branchCats->has($catId)) continue;
            $branchProducts = $byBranch[$catId] ?? [];
            if (empty($branchProducts)) continue;
            $b = $this->makeBranch($catId, $branchCats->get($catId), $branchProducts);
            if ($b) $result[] = $b;
        }
        return $result;
    }

    // ── Query sản phẩm → gom theo branchCatId ─────────────────────────────────
    private function fetchProductsByBranch(array $allCatIds, array $branchCatIds, array $childToBranch): array
    {
        $query = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $allCatIds))
            ->with(['categories:id', 'media', 'roomTimeSlots.timeSlot'])
            ->orderBy('sort_order');

        // Filter theo room type slug (tab trên hero)
        if ($this->filterType && $this->filterType !== 'all') {
            $query->whereHas('roomType', fn ($q) => $q->where('slug', $this->filterType));
        }

        // Filter theo styles: 1 = theo giờ, 2 = theo ngày
        if ($this->filterBuoi === '1' || $this->filterBuoi === '2') {
            $query->where('styles', (int) $this->filterBuoi);
        }

        // Loại phòng đã bị đặt trong khung giờ tìm kiếm
        if (!empty($this->bookedIds)) {
            $query->whereNotIn('id', $this->bookedIds);
        }

        $byBranch = [];
        foreach ($query->get() as $product) {
            $branchCatId = null;
            foreach ($product->categories as $cat) {
                if (in_array($cat->id, $branchCatIds)) { $branchCatId = $cat->id; break; }
                if (isset($childToBranch[$cat->id])) { $branchCatId = $childToBranch[$cat->id]; break; }
            }
            if ($branchCatId) $byBranch[$branchCatId][] = $product;
        }
        return $byBranch;
    }

    // ── Build 1 branch array ───────────────────────────────────────────────────
    private function makeBranch(int $catId, \Modules\Category\Entities\Category $cat, array $branchProducts): ?array
    {
        $image = null;
        foreach ($branchProducts as $p) {
            if ($p->media_file) { $image = asset('storage/' . $p->media_file); break; }
        }

        $withCoords = array_values(array_filter($branchProducts, fn ($p) => $p->latitude && $p->longitude));
        $lat = count($withCoords) ? array_sum(array_map(fn ($p) => (float) $p->latitude,  $withCoords)) / count($withCoords) : null;
        $lng = count($withCoords) ? array_sum(array_map(fn ($p) => (float) $p->longitude, $withCoords)) / count($withCoords) : null;

        $address = '';
        foreach ($branchProducts as $p) {
            if ($p->address) { $address = $p->address; break; }
        }

        $rooms = [];
        foreach (array_slice($branchProducts, 0, 15) as $p) {
            $minPrice = null;
            $minTime  = null;
            foreach ($p->roomTimeSlots as $slot) {
                if (!$slot->timeSlot) continue;
                try {
                    $start     = Carbon::parse($slot->timeSlot->start_time);
                    $end       = Carbon::parse($slot->timeSlot->end_time);
                    $totalMins = $start->diffInMinutes($end);
                    $h = floor($totalMins / 60); $m = $totalMins % 60;
                    $label = $m > 0 ? "{$h}h{$m}" : "{$h}h";
                    if ($minPrice === null || $slot->price < $minPrice) {
                        $minPrice = $slot->price;
                        $minTime  = $label;
                    }
                } catch (\Exception) {}
            }
            $rooms[] = [
                'slug'  => $p->slug,
                'name'  => $p->name,
                'image' => $p->media_file ? asset('storage/' . $p->media_file) : null,
                'price' => $minPrice,
                'time'  => $minTime,
                'url'   => route('product.detail', ['slug' => $p->slug]),
            ];
        }

        return [
            'cat_id'     => $catId,
            'name'       => $cat->name,
            'image'      => $image,
            'lat'        => $lat,
            'lng'        => $lng,
            'address'    => $address,
            'room_count' => count($branchProducts),
            'rooms'      => $rooms,
        ];
    }

    public function render()
    {
        return view('bladethemev1::livewire.search-results-grid');
    }
}
