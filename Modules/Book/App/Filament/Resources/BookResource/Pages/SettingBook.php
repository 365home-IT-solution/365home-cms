<?php

namespace Modules\Book\App\Filament\Resources\BookResource\Pages;

use Modules\Book\App\Filament\Resources\BookResource;
use Modules\Book\App\Filament\Traits\HasBookingHeaderActions;
use Modules\Book\App\Filament\Traits\HasRoomPricingFormFields;
use Filament\Resources\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\DatePicker;
use Modules\Book\App\Filament\Forms\Components\DatePriceCalendarField;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Promotion;
use Filament\Notifications\Notification;
use Modules\Promotion\App\Models\Coupon;
use Modules\DataPermission\Entities\UserBranchPermission;
use App\Services\PriceBoardSyncService;
use App\Services\DailyRoomPricingService;
use Modules\Product\App\Models\PriceBoardPriceLog;
use Filament\Actions\Action;
use Modules\AuditLog\Services\AuditLogger;

class SettingBook extends Page implements HasForms
{
    use InteractsWithForms;
    use HasRoomPricingFormFields;
    use HasBookingHeaderActions {
        getHeaderActions as private traitGetHeaderActions;
    }

    protected function getHeaderActions(): array
    {
        $isSuperAdmin = (bool) auth()->user()?->isSuperAdmin();

        $hiddenActions = $isSuperAdmin
            ? ['block_calendar']
            : ['block_calendar', 'create_coupon', 'create_promotion', 'bulk_price_update'];

        $actions = array_values(array_filter(
            $this->traitGetHeaderActions(),
            fn ($action) => ! in_array($action->getName(), $hiddenActions, true),
        ));

        if ($isSuperAdmin) {
            $actions[] = Action::make('price_history')
                ->label('Lịch sử')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->modalHeading('Lịch sử thay đổi giá')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng')
                ->modalContent(fn () => view('book::filament.resources.price-board-resource.history-modal', [
                    'logs' => PriceBoardPriceLog::with(['product', 'changedByUser', 'priceBoard'])
                        ->orderByDesc('created_at')
                        ->limit(200)
                        ->get(),
                ]));
        }

        return $actions;
    }

    protected static string $resource = BookResource::class;
    protected static ?string $slug = 'cai-dat-he-thong-booking';
    protected static ?string $title = 'Hệ thống giá';

    protected static string $view = 'book::filament.resources.book-resource.pages.setting-book';

    public ?array $data = [];

    /** Số phòng tối đa build form mỗi trang — trước đây load TOÀN BỘ phòng + toàn bộ khung giờ/
     *  khuyến mãi cùng lúc (mount() lẫn form() đều gọi ->get() không giới hạn), với đối tác nhiều
     *  phòng thì Filament phải dựng hàng trăm Tab + TableRepeater cùng lúc, tốn RAM rất lớn. Giờ
     *  chỉ build phòng của trang hiện tại (lọc thêm theo chi nhánh nếu có), giữ nguyên trải nghiệm
     *  vì mỗi phòng vẫn có Tab riêng như cũ. */
    public int $perPage = 12;

    /** Chi nhánh + trang đang lọc — đọc từ query string (?branch_id=, ?page=) đúng 1 LẦN trong
     *  mount() (chỉ chạy ở lần tải trang đầy đủ), rồi giữ nguyên qua các Livewire action call sau
     *  đó (applyBulkOverride, saveSingleDate...). KHÔNG được đọc trực tiếp request()->query() ở
     *  những nơi khác vì các action call là request AJAX riêng, không còn query string gốc của
     *  trang — đọc lại sẽ luôn ra rỗng và âm thầm reset về "tất cả chi nhánh, trang 1". */
    public ?string $branchId = null;
    public int $page = 1;

    /** ?product_id= (mở nhanh từ Dashboard) — cũng chỉ đọc 1 lần ở mount() như trên: trước đây đọc
     *  lại request()->query() trong currentRoomIds()/form(), nên sau thao tác đầu tiên trên lịch
     *  (request AJAX, không còn query string) phòng đó rơi khỏi form nếu nằm ngoài trang hiện tại. */
    public ?string $preselectProductId = null;

    /** Cache trong 1 request — currentRoomIds() bị gọi cả ở mount() lẫn form(), không muốn chạy
     *  lại query phân trang 2 lần. */
    private ?\Illuminate\Support\Collection $currentRoomIdsCache = null;

    /** Cache trong 1 request — scopedProductQuery() gọi applyBranchPriorityOrder() nhiều lần. */
    private ?array $priorityProductIdsCache = null;

    /** Thông tin phân trang để hiện ở blade (setting-book.blade.php) — được set trong
     *  currentRoomIds(), luôn sẵn sàng trước khi form() render vì mount() chạy trước. */
    public array $paginatorMeta = ['current_page' => 1, 'last_page' => 1, 'total' => 0];

    /** Danh sách chi nhánh (dùng cho dropdown lọc) — chi nhánh = category KHÔNG có parent mà 1
     *  phòng được gán trực tiếp, hoặc parent của category được gán (cùng quy ước với
     *  RoomCardsService::getData() ở Dashboard, để nhất quán khái niệm "chi nhánh" toàn hệ thống). */
    public function branchOptions(): array
    {
        $branches = [];

        foreach ($this->baseProductQuery()->with('categories.parent')->get() as $product) {
            $category = $product->categories->first();
            $branch   = $category?->parent ?: $category;

            if ($branch) {
                $branches[$branch->id] = $branch->name;
            }
        }

        asort($branches);

        return $branches;
    }

    /** ID các phòng sẽ build form ở request này: 1 trang (theo $perPage) của scopedProductQuery(),
     *  cộng thêm phòng được mở nhanh qua ?product_id= (xem mount()) nếu nó rơi ra ngoài trang hiện
     *  tại/bị lọc theo chi nhánh khác — đảm bảo link "Xem chi tiết" từ Dashboard luôn mở đúng phòng
     *  thay vì phải tự dò đúng chi nhánh + trang chứa nó. */
    private function currentRoomIds(): \Illuminate\Support\Collection
    {
        if ($this->currentRoomIdsCache !== null) {
            return $this->currentRoomIdsCache;
        }

        $paginator = $this->scopedProductQuery()->orderBy('name')->paginate($this->perPage, ['*'], 'page', $this->page);

        $this->paginatorMeta = [
            'current_page' => $paginator->currentPage(),
            'last_page'    => max(1, $paginator->lastPage()),
            'total'        => $paginator->total(),
        ];

        $ids = $paginator->getCollection()->pluck('id');

        $preselectId = $this->preselectProductId;
        if ($preselectId && ! $ids->contains($preselectId) && $this->baseProductQuery()->whereKey($preselectId)->exists()) {
            $ids->push($preselectId);
        }

        return $this->currentRoomIdsCache = $ids;
    }

    /** Returns product IDs the current user is allowed to manage. null = all. */
    private function allowedProductIds(): ?array
    {
        $user = auth()->user();
        if (! $user || $user->isSuperAdmin()) {
            return null;
        }

        $categoryIds = $user->allowedCategoryIds();
        if (empty($categoryIds)) {
            // Không thu hẹp thêm — Product đã tự lọc theo partner_id (BelongsToPartner),
            // null = không giới hạn gì thêm ngoài phạm vi đối tác của user.
            return null;
        }

        return \Modules\Category\Entities\Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $categoryIds)
            ->distinct()
            ->pluck('categorizable_id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    private function allowedPromotionOptions(): array
    {
        $user  = auth()->user();
        $query = Promotion::where('is_active', true);

        if (! $user || $user->isSuperAdmin()) {
            return $query->pluck('name', 'id')->toArray();
        }

        $branchIds          = UserBranchPermission::where('user_id', $user->id)->pluck('category_id');
        $overlappingUserIds = UserBranchPermission::whereIn('category_id', $branchIds)->pluck('user_id');

        return $query->where(function ($q) use ($overlappingUserIds) {
            $q->whereNull('created_by')->orWhereIn('created_by', $overlappingUserIds);
        })->pluck('name', 'id')->toArray();
    }

    private function baseProductQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::query()->where('is_activated', true);
        $ids   = $this->allowedProductIds();

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('id', $ids);
    }

    /** baseProductQuery() + lọc theo chi nhánh đang chọn (?branch_id=), cùng quy ước 1 cấp
     *  category/parent với branchOptions() ở trên. */
    private function scopedProductQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->baseProductQuery();

        if ($this->branchId) {
            $query->whereHas('categories', function ($q) {
                $q->where('categories.id', $this->branchId)->orWhere('categories.parent_id', $this->branchId);
            });

            return $query;
        }

        // Xem "Tất cả chi nhánh" — ưu tiên đẩy phòng của 252/254/89 lên đầu (nên rơi vào các
        // trang đầu tiên), phần còn lại vẫn sắp theo tên như cũ (xem orderBy('name') ở nơi gọi).
        $this->applyBranchPriorityOrder($query);

        return $query;
    }

    /** Danh sách ID phòng thuộc các chi nhánh cần ưu tiên (252, 254, 89 — theo đúng thứ tự), đã
     *  gộp cả phòng gán trực tiếp vào chi nhánh lẫn phòng gán vào category con của chi nhánh đó. */
    private function priorityProductIds(): array
    {
        if ($this->priorityProductIdsCache !== null) {
            return $this->priorityProductIdsCache;
        }

        $ids = [];

        foreach (['252 ', '254 ', '89 '] as $branchPrefix) {
            $branch = \Modules\Category\Entities\Category::where('category_type', 'product')
                ->whereNull('parent_id')
                ->where('name', 'like', $branchPrefix . '%')
                ->first();

            if (! $branch) {
                continue;
            }

            $categoryIds = array_merge(
                [$branch->id],
                \Modules\Category\Entities\Category::where('parent_id', $branch->id)->pluck('id')->toArray()
            );

            $branchProductIds = \Modules\Category\Entities\Categorizable::where('categorizable_type', Product::class)
                ->whereIn('category_id', $categoryIds)
                ->pluck('categorizable_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            foreach ($branchProductIds as $id) {
                if (! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $this->priorityProductIdsCache = $ids;
    }

    private function applyBranchPriorityOrder(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $priorityIds = $this->priorityProductIds();

        if (empty($priorityIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($priorityIds), '?'));

        // FIELD(id, ...) trả về 0 nếu KHÔNG khớp — "=0" đẩy các phòng không nằm trong danh sách ưu
        // tiên xuống cuối, còn phòng khớp thì sắp theo đúng thứ tự xuất hiện trong $priorityIds
        // (tức đúng thứ tự chi nhánh 252 → 254 → 89 đã liệt kê ở priorityProductIds()).
        $query->orderByRaw("(FIELD(id, {$placeholders}) = 0), FIELD(id, {$placeholders})", array_merge($priorityIds, $priorityIds));
    }

    // So sánh danh sách khung giờ (RoomTimeSlot) của 1 phòng TRƯỚC/SAU khi save() xoá-tạo-lại, ghi
    // 1 dòng audit log DUY NHẤT liệt kê khung giờ đã tăng/bớt — thay cho việc để mỗi khung giờ tự
    // bắn 1 dòng log riêng (đã tắt qua RoomTimeSlot::withoutAuditLog() ở save()). $oldSlots là
    // snapshot ĐẦY ĐỦ (kèm quan hệ timeSlot đã load) chụp TRƯỚC khi xoá — bắt buộc, vì RoomTimeSlot
    // không soft-delete nên khung giờ bị bớt sẽ không còn truy vấn lại được sau khi đã delete().
    private function logRoomTimeSlotChanges(Product $product, \Illuminate\Support\Collection $oldSlots): void
    {
        $newSlots = RoomTimeSlot::where('room_id', $product->id)
            ->with('timeSlot')
            ->get()
            ->keyBy(fn ($slot) => (string) $slot->id);

        $addedIds   = array_diff($newSlots->keys()->all(), $oldSlots->keys()->all());
        $removedIds = array_diff($oldSlots->keys()->all(), $newSlots->keys()->all());

        if (empty($addedIds) && empty($removedIds)) {
            return;
        }

        $labelFor = fn (RoomTimeSlot $slot) => ($slot->timeSlot?->label ?? ('#' . $slot->timeslot_id))
            . ($slot->price !== null ? ' (' . number_format((float) $slot->price, 0, ',', '.') . 'đ)' : '');

        $old = [];
        $new = [];

        if (! empty($removedIds)) {
            $old['khung_gio_da_bo'] = $oldSlots->only($removedIds)->map($labelFor)->implode(', ');
        }
        if (! empty($addedIds)) {
            $new['khung_gio_da_them'] = $newSlots->only($addedIds)->map($labelFor)->implode(', ');
        }

        AuditLogger::log(
            action: 'update',
            module: 'RoomTimeSlot',
            record: $product,
            old: $old,
            new: $new,
            label: 'Phòng ' . ($product->name ?? '#' . $product->id) . ' — Cập nhật khung giờ',
        );
    }

    public function mount(): void
    {
        $this->branchId = request()->query('branch_id') ?: null;
        $this->page     = max(1, (int) request()->query('page', 1));
        $this->preselectProductId = request()->query('product_id') ?: null;

        $this->fillFormData();
    }

    /** Build lại $formData cho đúng trang/chi nhánh hiện tại ($this->page/$this->branchId) — dùng
     *  bởi mount() (tải trang lần đầu) LẪN các action bulk-edit bên dưới (trước đây tự gọi lại
     *  $this->mount(), nhưng mount() giờ đọc query string nên không được gọi lại giữa request). */
    private function fillFormData(): void
    {
        $rooms = Product::whereIn('id', $this->currentRoomIds())
            ->with('roomTimeSlots.timeslot', 'roomTimeSlots.promotions', 'roomTimeSlots.coupons')
            ->orderBy('name')
            ->get();

        $formData = [];
        foreach ($rooms as $product) {
            $productStyle = (int) ($product->styles ?? 1);

            $roomData = [
                'product_id'            => $product->id,
                'product_name'          => $product->name,
                'price'                 => (float) $product->price,
                'full_booking_discount' => $product->full_booking_discount,
                'bulk_discount_rules'   => $product->bulk_discount_rules ?? [],
                'is_in_stock'           => (bool) $product->is_in_stock,
                'default_checkin'       => $product->default_checkin ?? '14:00',
                'default_checkout'      => $product->default_checkout ?? '12:00',
                'deposit_1_night'       => $product->deposit_1_night ?? 100,
                'deposit_multi_night'   => $product->deposit_multi_night ?? 50,
                'deposit_min_nights'    => $product->deposit_min_nights ?? 2,
                'room_config_max_free_guests' => (int)(($product->room_config['max_free_guests'] ?? 2)),
                'room_config_extra_guest_fee' => (int)(($product->room_config['extra_guest_fee'] ?? 50000)),
            ];

            if ($productStyle === 1) {
                $roomData['roomTimeSlots'] = $product->roomTimeSlots->map(function ($slot) {
                    return [
                        'id'          => $slot->id,
                        'timeslot_id' => $slot->timeslot_id,
                        'price'       => number_format((int) $slot->price, 0, ',', '.'),
                        'promotions'  => $slot->promotions->pluck('id')->toArray(),
                        'over_night'  => $slot->over_night,
                        'status'      => $slot->status,
                    ];
                })->toArray();
            } else {
                $roomData['dateTimeSlots'] = $product->roomTimeSlots
                    // Chỉ gửi từ đầu tháng trước trở đi — ngày đã qua lâu không sửa được nữa (lịch
                    // khoá ngày quá khứ) mà mỗi năm phình thêm ~365 dòng/phòng vào state Livewire.
                    ->filter(fn($s) => $s->timeslot && $s->timeslot->type === 'date'
                        && $s->timeslot->label >= now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'))
                    ->map(fn($slot) => [
                        'id'         => $slot->id,
                        'date'       => $slot->timeslot->label,
                        // null = giá chưa ghi đè (chỉ có khuyến mãi)
                        'price'      => $slot->price !== null ? number_format((int) $slot->price, 0, ',', '.') : null,
                        'promotions' => $slot->promotions->whereNotIn('type', ['increase_percentage', 'increase_fixed'])->pluck('id')->toArray(),
                        'surcharges' => $slot->promotions->whereIn('type', ['increase_percentage', 'increase_fixed'])->pluck('id')->toArray(),
                        'coupons'    => $slot->coupons->pluck('id')->toArray(),
                        'checkin'    => $slot->checkin,
                        'checkout'   => $slot->checkout,
                    ])
                    ->values()
                    ->toArray();
            }

            $formData['room_' . $product->id] = $roomData;
        }

        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        $rooms = Product::whereIn('id', $this->currentRoomIds())->orderBy('name')->get();

        // Phòng theo khung giờ: đối tác tự thiết lập khung giờ + giá từng khung, nhưng KHÔNG có cột
        // "Khuyến mãi" (chỉ super_admin) — save() cũng bỏ qua promotions từ đối tác. Lịch "Ngày đặc
        // biệt & Khuyến mãi" của phòng theo ngày (buildDayRoomTab) vẫn chỉ super_admin.
        $isSuperAdmin = (bool) auth()->user()?->isSuperAdmin();

        $buildSlotRoomTab = function ($product) use ($isSuperAdmin) {
            $prefix = 'room_' . $product->id . '.';

            $schema = $this->slotPricingSchema(
                $prefix,
                $isSuperAdmin,
                fn () => $this->allowedPromotionOptions(),
                [
                    Hidden::make($prefix . 'product_id')->default($product->id),
                    TextInput::make($prefix . 'product_name')
                        ->label('Tên Phòng')
                        ->default($product->name)
                        ->disabled()
                        ->dehydrated(false),
                ]
            );

            return Tabs\Tab::make($product->name)
                ->icon('heroicon-o-home')
                ->schema($schema);
        };

        $buildDayRoomTab = function ($product) use ($isSuperAdmin) {
            $prefix = 'room_' . $product->id . '.';

            $schema = $this->dayPricingSchema(
                $prefix,
                [
                    Hidden::make($prefix . 'product_id')->default($product->id),
                    TextInput::make($prefix . 'product_name')
                        ->label('Tên Phòng')
                        ->default($product->name)
                        ->disabled()
                        ->dehydrated(false),
                ]
            );

            if ($isSuperAdmin) {
                $schema[] = Section::make('Ngày đặc biệt & Khuyến mãi')
                    ->description('Nhấn vào ô ngày để thiết lập giá và khuyến mãi. Ưu tiên cao hơn giá gốc của phòng.')
                    ->schema([
                        DatePriceCalendarField::make($prefix . 'dateTimeSlots')
                            ->roomId($product->id)
                            ->basePrice((int) ($product->price ?? 0))
                            ->defaultTimes($product->default_checkin, $product->default_checkout)
                            ->label('')
                            ->columnSpan('full'),
                    ]);
            }

            return Tabs\Tab::make($product->name)
                ->icon('heroicon-o-home')
                ->schema($schema);
        };

        $slotRooms = $rooms->filter(fn($p) => ((int)($p->styles ?? 1)) === 1)->values();
        $dayRooms  = $rooms->filter(fn($p) => ((int)($p->styles ?? 1)) === 2)->values();

        $slotTabs = $slotRooms->map(fn ($p) => $buildSlotRoomTab($p))->values()->all();
        $dayTabs  = $dayRooms->map(fn ($p) => $buildDayRoomTab($p))->values()->all();

        // Mở trang này từ menu thao tác nhanh trên thẻ phòng (Dashboard) với ?product_id=... —
        // tự nhảy sẵn tới ĐÚNG tab của phòng đó (cả tab kiểu "Theo Khung Giờ"/"Theo Ngày" lẫn tab
        // con của chính phòng), thay vì luôn mở tab đầu tiên rồi phải tự tìm lại.
        $activeStyleTab = 1;
        $activeRoomTab  = 1;
        $preselectId    = $this->preselectProductId;

        if ($preselectId) {
            $slotIndex = $slotRooms->search(fn ($p) => (string) $p->id === (string) $preselectId);
            $dayIndex  = $dayRooms->search(fn ($p) => (string) $p->id === (string) $preselectId);

            if ($slotIndex !== false) {
                $activeStyleTab = 1;
                $activeRoomTab  = $slotIndex + 1;
            } elseif ($dayIndex !== false) {
                $activeStyleTab = 2;
                $activeRoomTab  = $dayIndex + 1;
            }
        }

        return $form
            ->schema([
                Tabs::make('StyleTabs')
                    ->activeTab($activeStyleTab)
                    ->tabs([
                        Tabs\Tab::make('Theo Khung Giờ')
                            ->icon('heroicon-o-clock')
                            ->badge($slotRooms->count())
                            ->schema(
                                $slotTabs
                                    ? [Tabs::make('SlotRooms')->activeTab($activeStyleTab === 1 ? $activeRoomTab : 1)->tabs($slotTabs)->columnSpanFull()]
                                    : [\Filament\Forms\Components\Placeholder::make('no_slot')->label('')->content('Chưa có phòng nào theo kiểu Khung Giờ.')]
                            ),
                        Tabs\Tab::make('Theo Ngày')
                            ->icon('heroicon-o-calendar-days')
                            ->badge($dayRooms->count())
                            ->schema(
                                $dayTabs
                                    ? [Tabs::make('DayRooms')->activeTab($activeStyleTab === 2 ? $activeRoomTab : 1)->tabs($dayTabs)->columnSpanFull()]
                                    : [\Filament\Forms\Components\Placeholder::make('no_day')->label('')->content('Chưa có phòng nào theo kiểu Theo Ngày.')]
                            ),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->data ?? [];
            $updated = false;
            $allowedIds = $this->allowedProductIds(); // null = super_admin

            if (empty($data)) {
                Notification::make()->title('Lỗi: $this->data rỗng, form chưa được fill đúng')->danger()->send();
                return;
            }

            foreach ($data as $key => $roomData) {
                if (str_starts_with($key, 'room_') && is_array($roomData) && isset($roomData['product_id'])) {
                $productId = (string) $roomData['product_id'];
                    if ($allowedIds !== null && ! in_array($productId, $allowedIds, true)) {
                        continue;
                    }

                    $product = Product::find($productId);
                    if (!$product) continue;

                    $priceBoardService = app(PriceBoardSyncService::class);
                    $priceBefore = $priceBoardService->snapshotPricing($product);

                    $productStyle = (int) ($product->styles ?? 1);

                    // Update product info
                    $rawRules = $roomData['bulk_discount_rules'] ?? [];
                    $bulkRules = collect($rawRules)
                        ->filter(fn($r) => isset($r['slots'], $r['discount']))
                        ->map(fn($r) => [
                            'slots'    => (int) $r['slots'],
                            'discount' => (float) $r['discount'],
                        ])
                        ->sortBy('slots')
                        ->values()
                        ->toArray();

                    $updateData = [
                        'full_booking_discount' => $roomData['full_booking_discount'] ?? null,
                        'bulk_discount_rules'   => $bulkRules ?: null,
                        'is_in_stock'           => $roomData['is_in_stock'] ?? true,
                    ];

                    if ($productStyle === 1) {
                        $updateData['room_config'] = [
                            'max_free_guests' => (int) ($roomData['room_config_max_free_guests'] ?? 2),
                            'extra_guest_fee' => (int) preg_replace('/[^0-9]/', '', (string) ($roomData['room_config_extra_guest_fee'] ?? '0')),
                        ];
                    }

                    if ($productStyle === 2) {
                        $updateData['price']                = $roomData['price'] ?? 0;
                        $updateData['default_checkin']      = $roomData['default_checkin'] ?? '14:00';
                        $updateData['default_checkout']     = $roomData['default_checkout'] ?? '12:00';
                        $updateData['deposit_1_night']      = (int) ($roomData['deposit_1_night'] ?? 100);
                        $updateData['deposit_multi_night']  = (int) ($roomData['deposit_multi_night'] ?? 50);
                        $updateData['deposit_min_nights']   = (int) ($roomData['deposit_min_nights'] ?? 2);
                        $updateData['room_config'] = [
                            'max_free_guests' => (int) ($roomData['room_config_max_free_guests'] ?? 2),
                            'extra_guest_fee' => (int) preg_replace('/[^0-9]/', '', (string) ($roomData['room_config_extra_guest_fee'] ?? '50000')),
                        ];
                    }

                    $product->update($updateData);

                    // RoomTimeSlot dùng LogsAuditTrail (tự động log create/update/delete) — nhưng 2
                    // khối bên dưới xoá-tạo-lại TỪNG khung giờ của 1 phòng mỗi lần Lưu, nếu để tự
                    // nhiên sẽ ra rất nhiều dòng log vụn (mỗi khung giờ 1 dòng) cho cùng 1 lần bấm
                    // Lưu. Chụp lại danh sách id khung giờ TRƯỚC khi xoá/tạo, tạm tắt log tự động
                    // qua RoomTimeSlot::withoutAuditLog(), rồi tự ghi 1 dòng TÓM TẮT duy nhất bên
                    // dưới (đã tăng/bớt khung giờ nào, giá bao nhiêu).
                    $oldSlots = RoomTimeSlot::where('room_id', $productId)
                        ->with('timeSlot')
                        ->get()
                        ->keyBy(fn ($slot) => (string) $slot->id);

                    // Style=2: lịch "Ngày đặc biệt" KHÔNG lưu ở đây — mỗi thao tác trên lịch đã tự
                    // ghi DB ngay qua các action bên dưới (DailyRoomPricingService). Trước đây nút
                    // Lưu ghi lại toàn bộ ngày từ state form (xoá ngày không có trong state, sync lại
                    // KM/mã) → state cũ (mở 2 tab / 2 người cùng sửa) đè mất thay đổi lịch vừa làm.

                    // Style=1: lưu roomTimeSlots (timeslot_id NOT NULL)
                    if ($productStyle === 1 && isset($roomData['roomTimeSlots']) && is_array($roomData['roomTimeSlots'])) {
                        $canManagePromotions = $allowedIds === null;
                        // Đối tác chỉ được dùng khung giờ hệ thống/của chính mình (không dùng type=date).
                        $usableTimeslotIds = $canManagePromotions ? null : TimeSlot::forCurrentAdmin()
                            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
                            ->pluck('id')->map(fn ($id) => (string) $id)->all();

                        RoomTimeSlot::withoutAuditLog(function () use ($roomData, $productId, $canManagePromotions, $usableTimeslotIds) {
                            $existingIds = collect($roomData['roomTimeSlots'])->pluck('id')->filter()->toArray();
                            $slotsToDelete = RoomTimeSlot::where('room_id', $productId)
                                ->when(!empty($existingIds), fn($q) => $q->whereNotIn('id', $existingIds))
                                ->get();

                            foreach($slotsToDelete as $slotToDelete) {
                                $slotToDelete->promotions()->detach();
                                $slotToDelete->delete();
                            }

                            foreach ($roomData['roomTimeSlots'] as $slot) {
                                if ($usableTimeslotIds !== null && ! in_array((string) ($slot['timeslot_id'] ?? ''), $usableTimeslotIds, true)) {
                                    continue;
                                }

                                $slotData = [
                                    'room_id' => $productId,
                                    'timeslot_id' => $slot['timeslot_id'],
                                    'price' => (int) str_replace(['.', ','], '', $slot['price']),
                                    'over_night' => $slot['over_night'] ?? false,
                                    'status' => $slot['status'] ?? 'available',
                                ];

                                $roomTimeSlotModel = null;

                                if (!empty($slot['id'])) {
                                    $roomTimeSlotModel = RoomTimeSlot::where('room_id', $productId)->find($slot['id']);
                                    if ($roomTimeSlotModel) {
                                        $roomTimeSlotModel->update($slotData);
                                    }
                                } else {
                                    $roomTimeSlotModel = RoomTimeSlot::create($slotData);
                                }

                                // Đối tác không có cột "Khuyến mãi" — giữ nguyên khuyến mãi super_admin đã gắn.
                                if ($roomTimeSlotModel && $canManagePromotions) {
                                    $roomTimeSlotModel->promotions()->sync($slot['promotions'] ?? []);
                                }
                            }
                        });
                    }

                    $this->logRoomTimeSlotChanges($product, $oldSlots);

                    // Giữ "Bảng giá mặc định" luôn khớp với giá vừa lưu ở trang này — dùng làm
                    // điểm khôi phục khi 1 bảng giá đặt tên (Tết, khuyến mãi, đối tác...) hết hạn.
                    $freshProduct = $product->fresh();
                    $priceBoardService->seedDefaultBoard($freshProduct);

                    // Ghi lịch sử NẾU giá thật sự đổi — gắn vào "Bảng giá mặc định" vì đây là sửa
                    // trực tiếp, không qua bảng giá đặt tên nào.
                    $priceBoardService->logPriceChange(
                        $priceBoardService->defaultBoard()->id,
                        $freshProduct,
                        $priceBefore,
                        $priceBoardService->snapshotPricing($freshProduct)
                    );

                    $updated = true;
                }
            }

            if ($updated) {
                Notification::make()->title('Đã lưu thành công')->success()->send();
            } else {
                Notification::make()
                    ->title('Không có dữ liệu để lưu')
                    ->body('data keys: ' . implode(', ', array_keys($data)))
                    ->danger()->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lỗi: ' . $e->getMessage())
                ->body($e->getFile() . ':' . $e->getLine())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  LỊCH GIÁ THEO NGÀY (phòng styles=2) — gọi từ Alpine trong
    //  date-price-calendar.blade.php qua $wire.call(...). Mọi thao tác ghi đi qua
    //  App\Services\DailyRoomPricingService (dùng CHUNG với API admin cho app).
    //
    //  $weekdays: thứ ISO (1 = Thứ 2 … 7 = Chủ nhật) — rỗng = mọi ngày trong khoảng.
    //  Khoảng ngày luôn BAO GỒM cả 2 đầu ($startDate..$endDate), 1 ngày = đêm bắt
    //  đầu từ ngày đó.
    // ═════════════════════════════════════════════════════════════════════════

    public function applyBulkOverride(
        string  $roomId,
        string  $startDate,
        string  $endDate,
        int     $price,
        ?string $checkinTime,
        ?string $checkoutTime,
        array   $weekdays = []
    ): void {
        if ($price < 0) {
            Notification::make()->title('Giá không hợp lệ')->warning()->send();
            return;
        }

        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, 'Đã ghi đè giá',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->setPrice($room, $dates, $price, $checkinTime, $checkoutTime));
    }

    /** $replace = thay toàn bộ KM + phụ thu của các ngày đó (thay vì gắn thêm). */
    public function applyBulkPromotion(string $roomId, string $startDate, string $endDate, array $promotionIds, array $weekdays = [], bool $replace = false): void
    {
        $promotionIds = $this->onlyAllowedIds($promotionIds, 'promotion');

        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, $replace ? 'Đã thay khuyến mãi/phụ thu' : 'Đã gắn khuyến mãi/phụ thu',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->attachPromotions($room, $dates, $promotionIds, $replace));
    }

    /**
     * Tạo (hoặc dùng lại) KM theo GIÁ TRỊ nhập tay — "Giảm 40%", "Giảm 50.000đ", "Phụ thu 10%"...
     * — rồi trả về dạng option để Alpine thêm vào danh sách và gắn ngay (applyBulkPromotion /
     * saveSingleDate). $type: percentage | fixed | increase_percentage | increase_fixed.
     */
    public function createQuickPromotion(string $roomId, string $type, int $value, string $from, string $to): ?array
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return null;
        }

        try {
            $service = app(DailyRoomPricingService::class);

            return $service->promotionOption($service->quickPromotion($room, $type, $value, $from, $to, auth()->user()));
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
            return null;
        }
    }

    public function applyBulkCoupon(string $roomId, string $startDate, string $endDate, array $couponIds, array $weekdays = [], bool $replace = false): void
    {
        $couponIds = $this->onlyAllowedIds($couponIds, 'coupon');

        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, $replace ? 'Đã thay mã giảm giá' : 'Đã gắn mã giảm giá',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->attachCoupons($room, $dates, $couponIds, $replace));
    }

    /** Gỡ giá ghi đè + giờ riêng, giữ KM/mã. */
    public function removeBulkOverride(string $roomId, string $startDate, string $endDate, array $weekdays = []): void
    {
        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, 'Đã gỡ giá ghi đè',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->clearPrice($room, $dates));
    }

    /** $promoIds rỗng = gỡ tất cả thuộc nhóm $kind ('discount' | 'surcharge'). */
    public function removeBulkPromotion(string $roomId, string $startDate, string $endDate, array $promoIds = [], array $weekdays = [], ?string $kind = null): void
    {
        $kind = in_array($kind, ['discount', 'surcharge'], true) ? $kind : null;

        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, $kind === 'surcharge' ? 'Đã gỡ phụ thu' : 'Đã gỡ khuyến mãi',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->detachPromotions($room, $dates, $promoIds, $kind));
    }

    /** $couponIds rỗng = gỡ tất cả mã. */
    public function removeBulkCoupon(string $roomId, string $startDate, string $endDate, array $couponIds = [], array $weekdays = []): void
    {
        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, 'Đã gỡ mã giảm giá',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->detachCoupons($room, $dates, $couponIds));
    }

    /** Xoá MỌI cấu hình (giá, giờ, KM, phụ thu, mã) trong khoảng → về giá gốc. */
    public function removeBulkAll(string $roomId, string $startDate, string $endDate, array $weekdays = []): void
    {
        $this->runDailyBulk($roomId, $startDate, $endDate, $weekdays, 'Đã xoá cấu hình',
            fn (DailyRoomPricingService $s, Product $room, array $dates) => $s->clearDates($room, $dates));
    }

    /**
     * Lưu TRỌN 1 ngày (thay thế toàn bộ cấu hình ngày đó). Bỏ trống hết = ngày về giá gốc.
     */
    public function saveSingleDate(
        string  $roomId,
        string  $date,
        ?int    $price,
        ?string $checkin,
        ?string $checkout,
        array   $promoIds     = [],
        array   $surchargeIds = [],
        array   $couponIds    = []
    ): void {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        if ($price !== null && $price < 0) {
            Notification::make()->title('Giá không hợp lệ')->warning()->send();
            return;
        }

        app(DailyRoomPricingService::class)->saveDate(
            $room,
            $date,
            $price,
            $checkin,
            $checkout,
            $this->onlyAllowedIds(array_merge($promoIds, $surchargeIds), 'promotion', $this->attachedIds($room, $date, 'promotions')),
            $this->onlyAllowedIds($couponIds, 'coupon', $this->attachedIds($room, $date, 'coupons')),
        );

        $this->fillFormData();

        Notification::make()->title('Đã lưu ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))->success()->send();
    }

    /** Xoá toàn bộ cấu hình của 1 ngày → về giá gốc. */
    public function deleteSingleDate(string $roomId, string $date): void
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        app(DailyRoomPricingService::class)->clearDates($room, [$date]);

        $this->fillFormData();

        Notification::make()->title('Đã xoá thiết lập ngày ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))->success()->send();
    }

    public function removeSinglePromotion(string $roomId, string $date, int $promotionId): void
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        app(DailyRoomPricingService::class)->detachPromotions($room, [$date], [$promotionId]);
        $this->fillFormData();
    }

    public function removeSingleCoupon(string $roomId, string $date, int $couponId): void
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        app(DailyRoomPricingService::class)->detachCoupons($room, [$date], [$couponId]);
        $this->fillFormData();
    }

    /** Xoá tất cả ngày đặc biệt của phòng. */
    public function deleteAllDates(string $roomId): void
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        $count = app(DailyRoomPricingService::class)->clearAll($room);

        $this->fillFormData();

        Notification::make()->title("Đã xoá {$count} ngày đặc biệt")->success()->send();
    }

    // ── Helpers lịch giá theo ngày ───────────────────────────────────────────

    private function runDailyBulk(string $roomId, string $startDate, string $endDate, array $weekdays, string $title, \Closure $op): void
    {
        $room = $this->dailyRoomOrNotify($roomId);
        if (! $room) {
            return;
        }

        $service = app(DailyRoomPricingService::class);

        try {
            $dates = $service->dates($startDate, $endDate, $weekdays);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
            return;
        }

        if ($dates === []) {
            Notification::make()->title('Không có ngày nào khớp thứ đã chọn trong khoảng này')->warning()->send();
            return;
        }

        $count = $op($service, $room, $dates);

        $this->fillFormData();

        Notification::make()
            ->title("{$title}: {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y')
                . ($weekdays ? ' · ' . $this->weekdayLabel($weekdays) : ''))
            ->success()
            ->send();
    }

    /** Phòng theo ngày mà user được sửa lịch giá — lịch này chỉ hiện cho super_admin (xem form()),
     *  nên action gọi thẳng qua $wire cũng phải chặn đúng như vậy. */
    private function dailyRoomOrNotify(string $roomId): ?Product
    {
        $room = auth()->user()?->isSuperAdmin()
            ? $this->baseProductQuery()->whereKey($roomId)->first()
            : null;

        if (! $room || (int) ($room->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ hoặc bạn không có quyền sửa lịch giá')->danger()->send();
            return null;
        }

        return $room;
    }

    /** Lọc id KM/mã về đúng danh sách được phép chọn (DailyRoomPricingService::options()), giữ
     *  thêm $keep (id đang gắn sẵn ở ngày đó — KM đã hết hạn không còn trong danh sách chọn, lưu
     *  lại ngày không được âm thầm gỡ mất). */
    private function onlyAllowedIds(array $ids, string $kind, array $keep = []): array
    {
        $options = app(DailyRoomPricingService::class)->options(auth()->user());

        $allowed = $kind === 'coupon'
            ? array_column($options['coupons'], 'id')
            : array_merge(array_column($options['promotions'], 'id'), array_column($options['surcharges'], 'id'));

        $allowed = array_merge($allowed, $keep);

        return array_values(array_filter(array_map('intval', $ids), fn ($id) => in_array($id, $allowed, true)));
    }

    private function attachedIds(Product $room, string $date, string $relation): array
    {
        $slot = RoomTimeSlot::where('room_id', $room->id)
            ->whereHas('timeslot', fn ($q) => $q->where('type', 'date')->where('label', \Carbon\Carbon::parse($date)->format('Y-m-d')))
            ->with($relation)
            ->first();

        return $slot ? $slot->{$relation}->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }

    private function weekdayLabel(array $weekdays): string
    {
        $names = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
        $days  = array_unique(array_map('intval', $weekdays));
        sort($days);

        return implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days));
    }
}
