<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Services\TimeslotHoldService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Modules\BladeThemeV1\App\Models\BlindBag;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\BladeThemeV1\Traits\HasTimeSlots;
use Modules\BladeThemeV1\Traits\PropertiesProductDetail;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Traits\Book as BookTrait;
use Modules\Product\App\Models\RoomTimeSlot;

class Book extends Component
{
    use HandleConfigTrait, BookTrait;

    public $choose_room, $title_booking, $sub_title_booking;
    public $blindBag, $image_event;

    /** Chỉ true khi nhúng ở trang chủ — hiện thêm header tên chi nhánh vì trang chủ không có
     *  ngữ cảnh URL như /branch/{slug} để người dùng biết đang xem lịch của chi nhánh nào. */
    public bool $showBranchHeader = false;

    /** Danh sách tab (id + name) cho tất cả danh mục — nhẹ, không kèm sản phẩm/timeslot. */
    public array $categoryTabs = [];

    /** Danh mục đang active. */
    public ?int $activeCategoryId = null;

    /** Dữ liệu đầy đủ (sản phẩm + timeslot + đơn đã đặt) — chỉ load cho danh mục active. */
    public array $activeCategoryData = [];

    /** Số ngày đang render — chỉ tăng qua loadMoreDates(), giữ nhỏ để tránh render
     *  (số phòng x số khung giờ x số ngày) ô lịch cùng lúc gây tràn bộ nhớ PHP. */
    public int $visibleDaysCount = self::INITIAL_VISIBLE_DAYS;

    // Số ngày hiện mặc định / tối đa trong bảng lịch.
    const INITIAL_VISIBLE_DAYS = 15;
    const MAX_VISIBLE_DAYS = 31;
    const LOAD_MORE_DAYS_STEP = 10;
    // Trang chủ: cố định 12 ngày, không có "Xem thêm ngày" — xem $compactDays.
    const COMPACT_VISIBLE_DAYS = 12;

    /** true khi nhúng ở trang chủ (home-booking-board.blade.php): chỉ hiện COMPACT_VISIBLE_DAYS
     *  ngày và KHÔNG cho tải thêm — mỗi ngày thêm là (số phòng x số khung giờ x 2 layout) ô lịch
     *  trong HTML trang chủ. Muốn xem ngày xa hơn, khách bấm tên phòng sang trang chi tiết phòng. */
    public bool $compactDays = false;

    /** Layout lịch đang render: 'desktop' (book/_desktop-grid) hoặc 'mobile' (book/_mobile). Chỉ in
     *  ra HTML MỘT layout — trước đây in cả 2 rồi ẩn 1 bằng CSS (lg:hidden / hidden lg:block), mỗi ô
     *  lịch bị lặp 2 lần. Server đoán theo User-Agent lúc mount; trình duyệt tự đối chiếu với bề
     *  rộng màn hình thật và gọi setLayout() nếu lệch (xem book.blade.php). */
    public string $layout = 'desktop';

    /** true (chỉ trang chủ): HTML ban đầu KHÔNG chứa bảng lịch (hàng trăm ô, ~120KB) mà chỉ có khung
     *  chờ; trình duyệt gọi loadBoard() ngay khi khối lịch tới gần vùng nhìn (xem book.blade.php).
     *  Đổi chi nhánh (loadBranch()) cũng tải bảng thật luôn. */
    public bool $deferred = false;

    // Danh sách id các phòng đang hiển thị trên bảng — render thành 1 attribute DUY NHẤT
    // "data-room-ids" trên div gốc (book.blade.php), giống hệt cách ProductDetail expose
    // "data-product-id" — để resources/js/echo-client.js biết cần nghe kênh public nào
    // ("timeslot-holds.{roomId}") mà không phải quét rải rác nhiều attribute trong DOM.
    public function getRoomIdsProperty(): array
    {
        return collect($this->activeCategoryData['products'] ?? [])->pluck('id')->all();
    }

    /** Cache trong-request (không phải property Livewire, không persist giữa các lượt request)
     *  cho map hold real-time — tính 1 lần/lượt render dù bảng lịch gọi lại nhiều lần khi lặp
     *  qua từng ô (xem getActiveHoldsMap() bên dưới). */
    protected ?array $activeHoldsCache = null;

    // Nạp 1 LẦN toàn bộ hold đang hiệu lực cho tất cả room_time_slot_id đang hiển thị trên bảng
    // (mọi phòng, kể cả phòng "peek" chưa active — book/_desktop-grid.blade.php render đủ giá cho
    // tất cả), rồi book/_slot-cell.blade.php tra map này thay vì tự gọi
    // TimeslotHoldService::isHeldByAdmin() cho TỪNG ô — trước đây mỗi ô tự query (+ DELETE purge)
    // riêng, với bảng nhiều ngày/phòng có thể thành hàng trăm round-trip DB chỉ để vẽ 1 lượt bảng
    // (xem TimeslotHoldService::getActiveHoldsMap() để biết lý do đổi).
    public function getActiveHoldsMap(): array
    {
        if ($this->activeHoldsCache !== null) {
            return $this->activeHoldsCache;
        }

        $roomTimeSlotIds = collect($this->activeCategoryData['products'] ?? [])
            ->flatMap(fn ($room) => $room->roomTimeSlots->pluck('id'))
            ->unique()
            ->values()
            ->all();

        return $this->activeHoldsCache = app(TimeslotHoldService::class)->getActiveHoldsMap($roomTimeSlotIds);
    }

    // resources/js/echo-client.js nghe kênh public "timeslot-holds.{roomId}" cho MỌI phòng đang
    // hiển thị trên bảng (đọc data-room-ids ở book.blade.php) rồi tự gọi
    // Livewire.dispatch('timeslotHoldsChanged') mỗi khi 1 admin giữ/trả 1 khung giờ real-time (xem
    // App\Services\TimeslotHoldService) — method no-op này chỉ để ép Livewire render lại, các
    // blade con tự đọc lại đúng trạng thái "held".
    #[On('timeslotHoldsChanged')]
    public function onTimeslotHoldsChanged(): void {}

    // resources/js/ws-client.js nghe kênh Node WS "room:{roomId}:{date}" (event slot.updated) rồi
    // tự gọi Livewire.dispatch('roomAvailabilityChanged') mỗi khi admin đổi giá/khung giờ/khuyến
    // mãi ở SettingBook (xem SlotRealtimeService::broadcastBlockedRange). KHÁC với
    // onTimeslotHoldsChanged() ở trên — hold-status đọc live ngay trong blade con, còn dữ liệu
    // giá/khung giờ đã cache sẵn trong $activeCategoryData nên phải load lại thật sự.
    #[On('roomAvailabilityChanged')]
    public function onRoomAvailabilityChanged(): void
    {
        if (empty($this->activeCategoryId)) {
            return;
        }

        $this->loadActiveCategoryData();
    }

    public function mount($config, bool $showBranchHeader = false, bool $compactDays = false, bool $deferred = false)
    {
        $this->deferred = $deferred;
        $this->showBranchHeader = $showBranchHeader;
        $this->compactDays = $compactDays;
        $this->layout = $this->detectLayout();
        $this->visibleDaysCount = $this->initialVisibleDays();
        $this->setConfig($config);
        // Hoãn tải: chưa truy vấn phòng/khung giờ/đơn — để dành cho loadBoard().
        if (! $this->deferred) {
            $this->initializeData();
        }
        $this->blindBag = BlindBag::first();
    }

    /**
     * Nạp lại toàn bộ config cho 1 chi nhánh khác vào component Book đang mount sẵn — dùng ở
     * panel đặt lịch inline của trang tìm kiếm (?view=branches), nơi 1 Book component duy nhất
     * được tái sử dụng cho từng chi nhánh người dùng bấm chọn, thay vì điều hướng sang trang
     * chi tiết chi nhánh riêng. Reset state cũ trước khi nạp, phòng khi chi nhánh mới không có
     * phòng khả dụng (categoryTabs rỗng) — nếu không sẽ giữ lại dữ liệu của chi nhánh trước đó.
     */
    #[On('load-branch')]
    public function loadBranch(string $slug): void
    {
        $result = BranchBookConfig::build($slug);

        if (! $result) {
            return;
        }

        $this->deferred = false;
        $this->categoryTabs = [];
        $this->activeCategoryId = null;
        $this->activeCategoryData = [];
        $this->visibleDaysCount = $this->initialVisibleDays();

        $this->setConfig($result['bookConfig']);
        $this->initializeData();
    }


    protected function initializeData()
    {
        $this->choose_room = $this->getConfig('choose_room');
        $this->title_booking = $this->getConfig('title_booking', 'Tiêu đề đang cập nhật.');
        $this->sub_title_booking = $this->getConfig('sub_title_booking', '');
        $this->image_event = $this->getConfig('image_event', '');

        $categories = $this->choose_room['categories'] ?? [];
        $this->categoryTabs = $this->buildCategoryTabs($categories);

        if (!empty($this->categoryTabs)) {
            $this->activeCategoryId = (int) $this->categoryTabs[0]['id'];
            $this->loadActiveCategoryData();
        }
    }

    /**
     * Chỉ lấy id + tên danh mục để render các nút tab — không query sản phẩm/timeslot
     * cho những danh mục chưa được xem tới (đó là việc của loadActiveCategoryData()).
     */
    protected function buildCategoryTabs(array $categories): array
    {
        $categoryIds = collect($categories)->pluck('category_id')->filter()->all();
        $categoryNames = Category::whereIn('id', $categoryIds)->pluck('name', 'id');

        return collect($categories)
            ->filter(fn ($item) => !empty($item['category_id']))
            ->map(fn ($item) => [
                'id' => (int) $item['category_id'],
                'name' => $categoryNames[$item['category_id']] ?? 'Danh mục không xác định',
            ])
            ->values()
            ->all();
    }

    /**
     * Đổi tab: chỉ load dữ liệu đầy đủ cho danh mục được chọn, không re-fetch
     * nếu đã là tab hiện tại. Bắn event để Alpine reset lựa chọn cũ (thuộc danh mục khác).
     */
    public function setActiveCategoryTab(int $categoryId): void
    {
        if ($this->activeCategoryId === $categoryId) {
            return;
        }

        $this->activeCategoryId = $categoryId;
        $this->loadActiveCategoryData();
        $this->dispatch('book-category-changed');
    }

    protected function loadActiveCategoryData(): void
    {
        $categories = $this->choose_room['categories'] ?? [];
        $item = collect($categories)->firstWhere('category_id', $this->activeCategoryId);

        $this->activeCategoryData = $item ? $this->buildCategoryData($item) : [];
    }

    protected function buildCategoryData(array $item): array
    {
        $endDate = Carbon::now()->addMonth()->endOfDay();
        $startDate = Carbon::now()->startOfDay();

        $categoryId = $item['category_id'];
        $productIds = $this->extractProductIds($item['products']);

        $category = Category::find($categoryId);
        $categoryName = $category?->name ?? 'Danh mục không xác định';
        $parentName = $category?->parent?->name ?? null;

        $productsQuery = Product::whereIn('id', $productIds)
            ->where('is_activated', true)
            ->where(function ($q) {
                // Chỉ lấy phòng cấu hình theo khung giờ (styles = 1 hoặc null mặc định)
                $q->where('styles', 1)->orWhereNull('styles');
            })
            ->with([
                'roomTimeSlots' => function ($query) {
                    $query->join('time_slots', 'room_time_slots.timeslot_id', '=', 'time_slots.id')
                        ->select('room_time_slots.*')
                        ->orderBy('time_slots.start_time', 'asc');
                },
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => function ($query) {
                    $query->where('is_active', true);
                },
                'orderItems' => function ($query) use ($endDate, $startDate) {
                    $query->where('checkout_date', '>', $startDate)
                        ->where('checkin_date', '<=', $endDate);
                    $query->whereHas('order', function ($subQuery) {
                        $subQuery->whereIn('status', ['pending', 'paid', 'confirmed']);
                    });
                },
                'orderItems.order'
            ])
            ->get();

        $products = collect($productIds)->map(function ($productId) use ($productsQuery) {
            return $productsQuery->firstWhere('id', $productId);
        })->filter()->values();

        return [
            'id' => $categoryId,
            'name' => $categoryName,
            'parent_name' => $parentName,
            'products' => $products,
            // Phòng THEO NGÀY (styles=2) cùng chi nhánh — bảng khung giờ ở trên không hiển thị được
            // kiểu phòng này, nên in thành danh sách card dẫn sang trang chi tiết (chọn ngày ở đó).
            'daily' => $this->buildDailyCards((int) $categoryId),
        ];
    }

    /**
     * Card phòng theo ngày: ảnh, giá gốc / đêm và "giá từ" thấp nhất 30 ngày tới — tính đúng công
     * thức đặt phòng (giá ghi đè hoặc giá gốc → phụ thu → KM, PromotionCalculator::calculateForDate).
     * Trả mảng scalar (không phải model) để state Livewire gọn. Lấy THẲNG theo chi nhánh (gồm khu
     * vực con) chứ không theo danh sách products của config — config đó (BranchBookConfig::build(),
     * cấu hình trang chủ) chỉ chứa phòng khung giờ.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildDailyCards(int $branchId): array
    {
        if ($branchId <= 0) {
            return [];
        }

        $categoryIds = \Modules\Product\App\Models\PriceBoard::branchCategoryIds($branchId);

        $rooms = Product::where('is_activated', true)
            ->where('is_in_stock', true)
            ->where('styles', 2)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->orderBy('sort_order')
            ->with([
                'media',
                'categories',
                'roomType',
                'roomTimeSlots' => fn ($q) => $q->whereHas('timeSlot', fn ($q2) => $q2->where('type', 'date')
                    ->whereBetween('label', [now()->format('Y-m-d'), now()->addDays(29)->format('Y-m-d')])),
                'roomTimeSlots.timeSlot',
                'roomTimeSlots.promotions' => fn ($q) => $q->where('is_active', true),
            ])
            ->limit(24)
            ->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        $calculator = app(\App\Services\PromotionCalculator::class);
        $resolver   = app(\App\Services\PriceBoards\StayPriceResolver::class);

        return $rooms
            ->map(function (Product $room) use ($calculator, $resolver) {
                $base  = (float) $room->price;
                $slots = $room->roomTimeSlots->keyBy(fn ($rts) => $rts->timeSlot?->label);
                $min   = null;

                for ($day = now()->startOfDay(), $i = 0; $i < 30; $i++, $day->addDay()) {
                    $date  = $day->format('Y-m-d');
                    $rts   = $slots->get($date);
                    $price = $rts?->price !== null ? (float) $rts->price : $resolver->nightPrice($room, $date, $base);
                    $final = $calculator->calculateForDate($rts, $price, $date)['final_price'];
                    $min   = $min === null ? $final : min($min, $final);
                }

                $media = $room->getFirstMedia('Ảnh bìa') ?? $room->getFirstMedia('Thư viện') ?? $room->getFirstMedia();
                $image = $media ? ($media->hasGeneratedConversion('card') ? $media->getUrl('card') : $media->getUrl()) : null;

                return [
                    'id'         => (string) $room->id,
                    'name'       => $room->name,
                    'url'        => \Modules\BladeThemeV1\Support\BranchBookConfig::resolveProductUrl($room),
                    'image'      => $image,
                    'base_price' => (int) $base,
                    'from_price' => (int) ($min ?? $base),
                    'checkin'    => substr((string) ($room->default_checkin ?? '14:00'), 0, 5),
                    'checkout'   => substr((string) ($room->default_checkout ?? '12:00'), 0, 5),
                    'max_guests' => (int) ($room->room_config['max_free_guests'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    protected function extractProductIds($products)
    {
        if (empty($products)) {
            return [];
        }
        if (is_array($products) && isset($products[0]) && is_array($products[0]) && isset($products[0]['product_id'])) {
            return collect($products)->pluck('product_id')->toArray();
        }
        return $products;
    }

    public function loadMoreDates(): void
    {
        if (! $this->canLoadMoreDates()) {
            return;
        }

        $this->visibleDaysCount = min($this->visibleDaysCount + self::LOAD_MORE_DAYS_STEP, self::MAX_VISIBLE_DAYS);
    }

    /** Tải bảng lịch thật cho lần hiển thị đầu ở chế độ $deferred (gọi từ trình duyệt). */
    public function loadBoard(): void
    {
        if (! $this->deferred) {
            return;
        }

        $this->deferred = false;
        $this->initializeData();
    }

    public function setLayout(string $layout): void
    {
        if (in_array($layout, ['desktop', 'mobile'], true)) {
            $this->layout = $layout;
        }
    }

    // Điện thoại -> 'mobile', còn lại (máy tính, tablet) -> 'desktop' — cùng nguồn với các khối
    // khác (App\Support\ViewportMode). Tablet đang ở chiều dọc sẽ lệch — trình duyệt tự sửa qua setLayout().
    // Chế độ 'both' (tablet, cửa sổ máy tính hay đổi cỡ): dùng cookie vp_now — bề rộng thật lúc tải
    // trang trước (script đầu <head> ở layouts/master.blade.php) — để không phải gọi thêm setLayout()
    // + render lại lần nữa ngay sau khi tải (PageSpeed: ~57ms reflow + 1 request /livewire/update).
    private function detectLayout(): string
    {
        $mode = \App\Support\ViewportMode::current();
        if ($mode === \App\Support\ViewportMode::BOTH) {
            return ($_COOKIE['vp_now'] ?? null) === 'mobile' ? 'mobile' : 'desktop';
        }

        return $mode === \App\Support\ViewportMode::MOBILE ? 'mobile' : 'desktop';
    }

    public function canLoadMoreDates(): bool
    {
        return ! $this->compactDays && $this->visibleDaysCount < self::MAX_VISIBLE_DAYS;
    }

    private function initialVisibleDays(): int
    {
        return $this->compactDays ? self::COMPACT_VISIBLE_DAYS : self::INITIAL_VISIBLE_DAYS;
    }

    // URL trang chi tiết của từng phòng đang hiển thị (id => url) — chỉ dùng ở chế độ $compactDays
    // để tên phòng dẫn sang trang chi tiết (nơi xem được lịch các ngày xa hơn).
    public function roomDetailUrls($rooms): array
    {
        $urls = [];
        foreach ($rooms as $room) {
            $room->loadMissing(['categories', 'roomType']);
            $urls[$room->id] = \Modules\BladeThemeV1\Support\BranchBookConfig::resolveProductUrl($room);
        }

        return $urls;
    }

    public function getDatesForOneMonth()
{
    $dates = [];
    $now = Carbon::now();
    $cutoff = Carbon::today()->setTime(7, 30, 0);

    // Nếu chưa qua 7:30 sáng, hiển thị cả ngày hôm qua
    $startDate = $now->lt($cutoff) ? Carbon::yesterday() : Carbon::today();
    // Chỉ build đúng số ngày đang hiển thị — tránh render (số phòng x số khung giờ x
    // số ngày) ô lịch cùng lúc gây tràn bộ nhớ PHP. loadMoreDates() tăng dần con số
    // này (round-trip Livewire nhỏ, chỉ khi user thật sự bấm "Xem thêm ngày").
    $daysCount = $this->visibleDaysCount;

    for ($i = 0; $i < $daysCount; $i++) {
        $date = $startDate->copy()->addDays($i);
        $dates[] = [
            'day' => $this->getDayOfWeek($date->dayOfWeek),
            'date' => $date->format('d-m-Y'),
            'is_today' => $date->isToday(),
            'is_past' => $date->isPast() && !$date->isToday(),
        ];
    }

    return $dates;
}


    private function getDayOfWeek($dayOfWeek)
    {
        $days = [
            0 => 'CN',  // Chủ nhật
            1 => 'T2',  // Thứ 2
            2 => 'T3',
            3 => 'T4',
            4 => 'T5',
            5 => 'T6',
            6 => 'T7',
        ];

        return $days[$dayOfWeek] ?? '';
    }

    public function saveAndRedirect($selectedSlots)
    {
        $sortedSlots = collect($selectedSlots)->sortBy(function ($slot) {
            $date = Carbon::createFromFormat('d-m-Y', $slot['date'])->format('Y-m-d');
            return Carbon::parse("{$date} {$slot['startTime']}");
        })->values()->toArray();

        // ✅ Tính toán lại giá cho mỗi slot trước khi lưu vào session
        $enrichedSlots = [];
        foreach ($sortedSlots as $slot) {
            $roomId = $slot['roomId'];
            $timeslotId = $slot['timeslotId'];
            $dateString = $slot['date']; // format: d-m-Y

            // Tìm RoomTimeSlot
            $roomTimeSlot = RoomTimeSlot::with(['timeSlot', 'promotions'])
                ->where('room_id', $roomId)
                ->where('timeslot_id', $timeslotId)
                ->first();

            if ($roomTimeSlot) {
                // Tính giá với promotion
                $priceData = $this->calculateSlotPrice(
                    $roomTimeSlot,
                    $dateString,
                    $slot['startTime'],
                    $sortedSlots, // Truyền tất cả slots để kiểm tra full booking
                    $roomId
                );

                $enrichedSlots[] = [
                    'date' => $dateString,
                    'timeslotId' => $timeslotId,
                    'startTime' => $slot['startTime'],
                    'endTime' => $slot['endTime'],
                    'price' => $priceData['final_price'], // ✅ Giá cuối cùng sau tất cả promotion
                    'originalPrice' => $priceData['price_after_increase'], // ✅ Giá sau tăng
                    'basePrice' => $priceData['original_price'], // ✅ Giá cơ bản
                    'increaseAmount' => $priceData['increase_amount'] ?? 0, // ✅ Số tiền tăng
                    'promoDiscount' => $priceData['total_discount'], // ✅ Số tiền giảm
                    'roomId' => $roomId,
                    'overNight' => $slot['overNight'] ?? 0,
                ];
            } else {
                // Bỏ qua slot không hợp lệ (room/timeslot không tồn tại trong DB)
                continue;
            }
        }

        if (empty($enrichedSlots)) {
            return;
        }

        // Lấy mốc bắt đầu/kết thúc THỰC của từng slot (mỗi slot tự cộng thêm 1 ngày nếu là
        // khung giờ qua đêm), rồi lấy min(start) → max(end) trên toàn bộ slot đã chọn — tránh
        // trường hợp slot có startTime muộn nhất chưa chắc có endTime muộn nhất.
        $slotStarts = [];
        $slotEnds = [];
        foreach ($enrichedSlots as $slot) {
            $slotDate = Carbon::createFromFormat('d-m-Y', $slot['date'])->format('Y-m-d');
            $slotStart = Carbon::parse("{$slotDate} {$slot['startTime']}");
            $slotEnd = Carbon::parse("{$slotDate} {$slot['endTime']}");

            if ((($slot['overNight'] ?? 0) == 1) || $slotEnd->lt($slotStart)) {
                $slotEnd->addDay();
            }

            $slotStarts[] = $slotStart;
            $slotEnds[] = $slotEnd;
        }

        $startTime = collect($slotStarts)->min()->format('Y-m-d H:i');
        $endTime = collect($slotEnds)->max()->format('Y-m-d H:i');

        Session::put('booking_data', [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'selected_slots' => $enrichedSlots, // ✅ Lưu slots đã có đầy đủ thông tin giá
            'room_id' => $enrichedSlots[0]['roomId'],
        ]);

        $firstRoomSlug = Product::where('id', $enrichedSlots[0]['roomId'])->value('slug');

        return redirect()->route('product.detail', ['slug' => $firstRoomSlug ?? 'default-slug']);
    }

    /**
     * Tính giá cho 1 slot với xử lý promotion theo thời gian cụ thể
     *
     * @param object $slot - Room time slot object
     * @param string $dateString - Date in format 'd-m-Y'
     * @param string|null $slotStartTime - Start time in format 'H:i:s'
     * @param array $selectedSlots - Danh sách slots đã chọn (để kiểm tra full booking)
     * @param int|null $roomId - ID của phòng
     * @return array
     */
    public function calculateSlotPrice($slot, $dateString, $slotStartTime = null, $selectedSlots = [], $roomId = null)
    {
        $originalPrice = (float) ($slot instanceof \Modules\Product\App\Models\RoomTimeSlot
            ? ($slot->priceOn(Carbon::createFromFormat('d-m-Y', $dateString)) ?? $slot->price)
            : $slot->price);
        $priceAfterIncrease = $originalPrice;
        $finalPrice = $originalPrice;
        $totalDiscount = 0;
        $activePromotions = [];

        // Kiểm tra xem ngày này có phải full booking không
        $isFullDayBooking = false;
        if ($roomId && !empty($selectedSlots)) {
            $isFullDayBooking = $this->hasFullDayBookingForDate($selectedSlots, $roomId, $dateString);
        }

        // Tạo datetime đầy đủ để so sánh
        if ($slotStartTime) {
            $timeString = strlen($slotStartTime) === 5 ? $slotStartTime . ':00' : $slotStartTime;
            $slotDateTime = Carbon::createFromFormat('d-m-Y H:i:s', $dateString . ' ' . $timeString);
        } else {
            $slotDateTime = Carbon::createFromFormat('d-m-Y', $dateString)->startOfDay();
        }

        $currentDate = Carbon::createFromFormat('d-m-Y', $dateString)->startOfDay();

        // Nếu là full day booking, BỎ QUA tất cả promotions giảm giá, chỉ giữ tăng giá
        if ($isFullDayBooking) {
            // Chỉ áp dụng promotion TĂNG GIÁ
            foreach ($slot->promotions as $promotion) {
                if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                    continue;
                }

                $value = (float) $promotion->value;

                if (in_array($promotion->type, ['increase_fixed', 'increase_percentage'])) {
                    switch ($promotion->type) {
                        case 'increase_fixed':
                            $priceAfterIncrease += $value;
                            $finalPrice += $value;
                            break;
                        case 'increase_percentage':
                            $increaseAmount = ($priceAfterIncrease * ($value / 100));
                            $priceAfterIncrease += $increaseAmount;
                            $finalPrice += $increaseAmount;
                            break;
                    }
                    $activePromotions[] = $promotion;
                }
            }

            return [
                'final_price' => max(0, $finalPrice),
                'original_price' => $originalPrice,
                'price_after_increase' => $priceAfterIncrease,
                'increase_amount' => $priceAfterIncrease - $originalPrice,
                'total_discount' => 0, // Không có discount khi full booking
                'has_promotion' => count($activePromotions) > 0,
                'is_increase' => $priceAfterIncrease > $originalPrice,
                'promotions' => $activePromotions,
                'is_full_day_booking' => true
            ];
        }

        // Logic cũ cho trường hợp KHÔNG phải full booking
        if ($slot->promotions->isEmpty()) {
            return [
                'final_price' => $finalPrice,
                'original_price' => $originalPrice,
                'price_after_increase' => $originalPrice,
                'increase_amount' => 0,
                'total_discount' => 0,
                'has_promotion' => false,
                'is_increase' => false,
                'promotions' => [],
                'is_full_day_booking' => false
            ];
        }

        // Bước 1: Áp dụng các promotion TĂNG GIÁ trước
        foreach ($slot->promotions as $promotion) {
            if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                continue;
            }

            $value = (float) $promotion->value;

            if (in_array($promotion->type, ['increase_fixed', 'increase_percentage'])) {
                switch ($promotion->type) {
                    case 'increase_fixed':
                        $priceAfterIncrease += $value;
                        $finalPrice += $value;
                        break;
                    case 'increase_percentage':
                        $increaseAmount = ($priceAfterIncrease * ($value / 100));
                        $priceAfterIncrease += $increaseAmount;
                        $finalPrice += $increaseAmount;
                        break;
                }
                $activePromotions[] = $promotion;
            }
        }

        // Bước 2: Áp dụng các promotion GIẢM GIÁ sau
        foreach ($slot->promotions as $promotion) {
            if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                continue;
            }

            $value = (float) $promotion->value;

            if (in_array($promotion->type, ['fixed', 'percentage'])) {
                switch ($promotion->type) {
                    case 'fixed':
                        $finalPrice -= $value;
                        $totalDiscount += $value;
                        break;
                    case 'percentage':
                        $discount = ($finalPrice * ($value / 100));
                        $finalPrice -= $discount;
                        $totalDiscount += $discount;
                        break;
                }

                $exists = collect($activePromotions)->contains(fn($p) => $p->id === $promotion->id);
                if (!$exists) {
                    $activePromotions[] = $promotion;
                }
            }
        }

        $finalPrice = max(0, $finalPrice);

        return [
            'final_price' => $finalPrice,
            'original_price' => $originalPrice,
            'price_after_increase' => $priceAfterIncrease,
            'increase_amount' => $priceAfterIncrease - $originalPrice,
            'total_discount' => $totalDiscount,
            'has_promotion' => count($activePromotions) > 0,
            'is_increase' => $priceAfterIncrease > $originalPrice,
            'promotions' => $activePromotions,
            'is_full_day_booking' => false
        ];
    }

    protected function isPromotionApplicable($promotion, Carbon $slotDateTime, Carbon $currentDate, $slot = null)
    {
        // --- BƯỚC 1: LẤY KHOẢNG THỜI GIAN TUYỆT ĐỐI CỦA PROMOTION ---
        $promotionStart = Carbon::parse($promotion->start_at);
        $promotionEnd = Carbon::parse($promotion->end_at);

        // --- BƯỚC 2: XÁC ĐỊNH KHOẢNG THỜI GIAN CỦA SLOT ---
        // Mặc định slot kéo dài 1 tiếng nếu không tìm thấy cấu hình end_time
        $slotStart = $slotDateTime->copy();
        $slotEnd = $slotStart->copy()->addHour();

        if ($slot && isset($slot->timeSlot)) {
            $endTimeStr = $slot->timeSlot->end_time; // Lấy "12:20:00" từ DB
            $slotEnd = Carbon::createFromFormat('d-m-Y H:i:s', $currentDate->format('d-m-Y') . ' ' . $endTimeStr);

            // Xử lý nếu slot kết thúc qua ngày hôm sau (ví dụ 23:00 -> 01:00)
            if ($slotEnd->lt($slotStart)) {
                $slotEnd->addDay();
            }
        }

        /**
         * LOGIC GIAO THOA (OVERLAP):
         * Slot được áp dụng nếu: (Bắt đầu Slot < Kết thúc Promo) VÀ (Kết thúc Slot > Bắt đầu Promo)
         * Với Slot 09:30-12:20 và Promo bắt đầu lúc 12:00:
         * (09:30 < PromoEnd) AND (12:20 > 12:00) => THỎA MÃN
         */
        $isOverlapping = $slotStart->lt($promotionEnd) && $slotEnd->gt($promotionStart);

        if (!$isOverlapping) {
            return false;
        }

        // --- BƯỚC 3: KIỂM TRA NGÀY BỊ LOẠI TRỪ (EXCLUDED DATES) ---
        $excludedDates = $promotion->excluded_dates ?? [];
        if (is_string($excludedDates)) {
            $excludedDates = json_decode($excludedDates, true);
        }
        $isExcluded = collect($excludedDates)->contains(function ($exDate) use ($currentDate) {
            $exDateVal = isset($exDate['date']) ? $exDate['date'] : $exDate;
            return Carbon::parse($exDateVal)->isSameDay($currentDate);
        });
        if ($isExcluded) return false;

        // --- BƯỚC 4: KIỂM TRA KHUNG GIỜ CỐ ĐỊNH (APPLICABLE TIME SLOTS) ---
        // Nếu promo yêu cầu chỉ áp dụng vào giờ vàng lặp lại mỗi ngày
        if (isset($promotion->applicable_time_slots) && !empty($promotion->applicable_time_slots)) {
            $applicableTimeSlots = is_string($promotion->applicable_time_slots)
                ? json_decode($promotion->applicable_time_slots, true)
                : $promotion->applicable_time_slots;

            if (!empty($applicableTimeSlots)) {
                $slotStartTimeStr = $slotStart->format('H:i');
                $slotEndTimeStr = $slotEnd->format('H:i');

                $isTimeApplicable = collect($applicableTimeSlots)->contains(function ($timeRange) use ($slotStartTimeStr, $slotEndTimeStr) {
                    if (isset($timeRange['start']) && isset($timeRange['end'])) {
                        $pStart = substr($timeRange['start'], 0, 5);
                        $pEnd = substr($timeRange['end'], 0, 5);

                        // Slot giao thoa với khung giờ cấu hình lặp lại
                        return $slotStartTimeStr < $pEnd && $slotEndTimeStr > $pStart;
                    }
                    return false;
                });

                if (!$isTimeApplicable) return false;
            }
        }

        // --- BƯỚC 5: KIỂM TRA THỨ TRONG TUẦN (DAYS OF WEEK) ---
        if (isset($promotion->applicable_days_of_week) && !empty($promotion->applicable_days_of_week)) {
            $applicableDays = is_string($promotion->applicable_days_of_week)
                ? json_decode($promotion->applicable_days_of_week, true)
                : $promotion->applicable_days_of_week;

            if (!in_array($slotStart->dayOfWeek, $applicableDays)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Kiểm tra xem có đặt full phòng trong 1 ngày không
     */
    protected function isFullDayBooking($selectedSlots, $roomId)
    {
        if (empty($selectedSlots)) {
            return false;
        }

        // Lấy tất cả timeslot của phòng
        $room = Product::with('roomTimeSlots')->find($roomId);
        if (!$room) {
            return false;
        }

        $totalTimeslots = $room->roomTimeSlots->count();
        if ($totalTimeslots === 0) {
            return false;
        }

        // Nhóm các slot đã chọn theo ngày
        $slotsByDate = collect($selectedSlots)->groupBy('date');

        // Kiểm tra xem có ngày nào đặt đủ số khung giờ không
        foreach ($slotsByDate as $date => $slots) {
            if (count($slots) === $totalTimeslots) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tính giảm giá full booking
     */
    protected function calculateFullBookingDiscount($totalPrice, $fullBookingDiscount)
    {
        if (empty($fullBookingDiscount)) {
            return 0;
        }

        // Kiểm tra xem là % hay số tiền cố định
        if (str_contains($fullBookingDiscount, '%')) {
            $percentage = (float) str_replace('%', '', $fullBookingDiscount);
            return $totalPrice * ($percentage / 100);
        } else {
            return (float) str_replace(['.', ','], '', $fullBookingDiscount);
        }
    }

    /**
     * Lấy danh sách các promotion đang active cho slot
     */
    public function getActivePromotionsDetail($slot, $dateString, $slotStartTime = null)
    {
        $result = [
            'increase' => [],
            'discount' => []
        ];

        if ($slotStartTime) {
            $timeString = strlen($slotStartTime) === 5 ? $slotStartTime . ':00' : $slotStartTime;
            $slotDateTime = Carbon::createFromFormat('d-m-Y H:i:s', $dateString . ' ' . $timeString);
        } else {
            $slotDateTime = Carbon::createFromFormat('d-m-Y', $dateString)->startOfDay();
        }

        $currentDate = Carbon::createFromFormat('d-m-Y', $dateString)->startOfDay();

        foreach ($slot->promotions as $promotion) {
            if (!$this->isPromotionApplicable($promotion, $slotDateTime, $currentDate, $slot)) {
                continue;
            }

            $value = (float) $promotion->value;

            if (in_array($promotion->type, ['increase_fixed', 'increase_percentage'])) {
                $result['increase'][] = [
                    'name' => $promotion->name,
                    'type' => $promotion->type,
                    'value' => $value,
                    'lable_client' => $promotion->lable_client,
                    'image' => $promotion->image
                ];
            } elseif (in_array($promotion->type, ['fixed', 'percentage'])) {
                $result['discount'][] = [
                    'name' => $promotion->name,
                    'type' => $promotion->type,
                    'value' => $value
                ];
            }
        }

        return $result;
    }


    protected function hasFullDayBookingForDate($selectedSlots, $roomId, $dateString)
    {
        if (empty($selectedSlots)) {
            return false;
        }
        $room = Product::with('roomTimeSlots')->find($roomId);
        if (!$room || empty($room->full_booking_discount)) {
            return false;
        }

        $totalTimeslots = $room->roomTimeSlots->count();
        if ($totalTimeslots === 0) {
            return false;
        }
        $slotsForDate = collect($selectedSlots)->where('date', $dateString)->count();

        return $slotsForDate === $totalTimeslots;
    }

    public function render()
    {
        $now = Carbon::now();
        return view('bladethemev1::livewire.book', [
            'today_date' => $now->format('Y-m-d'),
            'current_time' => $now->format('H:i:s'),
        ]);
    }
}