<?php

namespace Modules\Book\App\Filament\Resources\BookResource\Pages;

use Modules\Book\App\Filament\Resources\BookResource;
use Modules\Book\App\Filament\Traits\HasBookingHeaderActions;
use Filament\Resources\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Modules\Book\App\Filament\Forms\Components\DatePriceCalendarField;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Promotion;
use Filament\Notifications\Notification;
use Modules\Promotion\App\Models\Coupon;
use Modules\DataPermission\Entities\UserBranchPermission;
use App\Services\SlotRealtimeService;

class SettingBook extends Page implements HasForms
{
    use InteractsWithForms;
    use HasBookingHeaderActions {
        getHeaderActions as private traitGetHeaderActions;
    }

    protected function getHeaderActions(): array
    {
        return array_values(array_filter(
            $this->traitGetHeaderActions(),
            fn ($action) => $action->getName() !== 'block_calendar',
        ));
    }

    protected static string $resource = BookResource::class;
    protected static ?string $slug = 'cai-dat-he-thong-booking';
    protected static ?string $title = 'Hệ thống Giá & Ưu đãi';

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

        $preselectId = request()->query('product_id');
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

    /** Báo cho khách đang xem lịch phòng (book.blade/product-detail) biết các ngày này vừa đổi
     *  giá/khuyến mãi/mã giảm giá — đẩy qua Node WS (room:{roomId}:{date}, event slot.updated,
     *  status "available") để trang khách tự fetch lại thay vì phải F5. Không throw nếu WS server
     *  không chạy — SlotRealtimeService tự bắt lỗi + timeout ngắn (xem app/Services/
     *  SlotRealtimeService.php), nên an toàn gọi vô điều kiện sau mỗi lần lưu ở đây. */
    private function broadcastDatesChanged(string $roomId, array $dates): void
    {
        $dates = array_values(array_unique(array_filter($dates)));

        if (empty($dates)) {
            return;
        }

        app(SlotRealtimeService::class)->broadcastBlockedRange($roomId, $dates, [], 'available');
    }

    /** Danh sách ngày (Y-m-d) từ $start đến $end (bao gồm cả 2 đầu) — dùng để báo realtime cho các
     *  thao tác hàng loạt theo khoảng ngày (applyBulkOverride, removeBulkPromotion...). */
    private function dateRange(\Carbon\Carbon $start, \Carbon\Carbon $end): array
    {
        $dates = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    public function mount(): void
    {
        $this->branchId = request()->query('branch_id') ?: null;
        $this->page     = max(1, (int) request()->query('page', 1));

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
                    ->filter(fn($s) => $s->timeslot && $s->timeslot->type === 'date')
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

        // Chỉ super_admin được xem/sửa Khung giờ, giá và Thiết lập khuyến mãi — các role khác chỉ
        // được thao tác trên Thông tin Phòng, nên 2 section thiết lập giá/khuyến mãi theo khung giờ
        // (buildSlotRoomTab) và theo ngày (buildDayRoomTab) bị ẩn hẳn khỏi form với các role khác.
        $isSuperAdmin = (bool) auth()->user()?->isSuperAdmin();

        $buildSlotRoomTab = function ($product) use ($isSuperAdmin) {
            $schema = [
                Section::make('Thông tin Phòng')
                        ->schema([
                            Hidden::make('room_' . $product->id . '.product_id')
                                ->default($product->id),
                            TextInput::make('room_' . $product->id . '.product_name')
                                ->label('Tên Phòng')
                                ->default($product->name)
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('room_' . $product->id . '.full_booking_discount')
                                ->label('Giảm giá khi đặt Full phòng')
                                ->placeholder('VD: 10% hoặc 50000')
                                ->helperText('Nhập % (VD: 10%) hoặc số tiền cố định (VD: 50000)')
                                ->maxLength(50),

                            TextInput::make('room_' . $product->id . '.room_config_max_free_guests')
                                ->label('Số khách tối đa không phụ thu')
                                ->numeric()
                                ->default(2)
                                ->minValue(1)
                                ->suffix('người')
                                ->helperText('Từ người thứ (N+1) trở đi sẽ tính phụ thu.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),

                            TextInput::make('room_' . $product->id . '.room_config_extra_guest_fee')
                                ->label('Phụ thu mỗi người vượt ngưỡng')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->suffix('đ/người')
                                ->helperText('0 = không phụ thu. Chỉ áp dụng cho đặt theo giờ.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),

                            Repeater::make('room_' . $product->id . '.bulk_discount_rules')
                                ->label('Giảm giá theo số khung giờ')
                                ->helperText('Cấu hình % giảm khi khách chọn nhiều khung giờ. Ví dụ: 2 khung → 5%, 3 khung → 10%.')
                                ->schema([
                                    TextInput::make('slots')
                                        ->label('Số khung giờ')
                                        ->numeric()
                                        ->minValue(2)
                                        ->maxValue(99)
                                        ->required()
                                        ->suffix('khung')
                                        ->extraInputAttributes(['inputmode' => 'numeric']),
                                    TextInput::make('discount')
                                        ->label('Giảm')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->required()
                                        ->suffix('%')
                                        ->extraInputAttributes(['inputmode' => 'numeric']),
                                ])
                                ->columns(2)
                                ->defaultItems(0)
                                ->addActionLabel('Thêm mức giảm')
                                ->reorderableWithButtons()
                                ->orderColumn('slots')
                                ->columnSpanFull(),
                        ])->columns(2),
            ];

            if ($isSuperAdmin) {
                $schema[] = Section::make('Khung giờ, giá, Thiết lập khuyến mãi')
                    ->description('Thiết lập khung giờ, giá và khuyến mãi cho phòng ' . $product->name)
                    ->schema([
                        TableRepeater::make('room_' . $product->id . '.roomTimeSlots')
                            ->headers([
                                Header::make('timeslot_id')->label('Khung giờ')->width('200px'),
                                Header::make('price')->label('Giá')->width('150px'),
                                Header::make('promotions')->label('Khuyến mãi')->width('250px'),
                                Header::make('over_night')->label('Qua đêm')->width('100px'),
                            ])
                            ->schema([
                                Hidden::make('id'),

                                Select::make('timeslot_id')
                                    ->label('Khung giờ')
                                    ->options(TimeSlot::all()->pluck('label', 'id'))
                                    ->preload()
                                    ->searchable()
                                    ->createOptionForm([
                                        TextInput::make('label')->required(),
                                        TextInput::make('start_time')
                                            ->label('Giờ bắt đầu')
                                            ->required()
                                            ->placeholder('07:50')
                                            ->helperText('Định dạng HH:MM (VD: 07:50)')
                                            ->rules(['regex:/^\d{1,2}:\d{2}$/']),
                                        TextInput::make('end_time')
                                            ->label('Giờ kết thúc')
                                            ->required()
                                            ->placeholder('10:40')
                                            ->helperText('Định dạng HH:MM (VD: 10:40)')
                                            ->rules(['regex:/^\d{1,2}:\d{2}$/']),
                                    ])
                                    ->createOptionUsing(fn (array $data) => TimeSlot::create(array_merge($data, ['type' => 'time']))->id)
                                    ->required(),

                                TextInput::make('price')
                                    ->label('Giá')
                                    ->required()
                                    ->suffix('VNĐ')
                                    ->extraInputAttributes(['inputmode' => 'numeric']),

                                Select::make('promotions')
                                    ->label('Khuyến mãi')
                                    ->options(fn () => $this->allowedPromotionOptions())
                                    ->multiple()
                                    ->preload()
                                    ->searchable(),

                                Toggle::make('over_night')
                                    ->label('Qua đêm')
                                    ->default(false),

                                Hidden::make('status')->default('available'),
                            ])
                            ->defaultItems(0)
                            ->columns(4)
                            ->label('')
                            ->emptyLabel('Chưa thiết lập cấu hình giá')
                            ->reorderableWithButtons()
                            ->reorderable(true)
                            ->createItemButtonLabel('Thêm khung giờ')
                            ->columnSpan('full')
                            ->collapsible()
                    ]);
            }

            return Tabs\Tab::make($product->name)
                ->icon('heroicon-o-home')
                ->schema($schema);
        };

        $buildDayRoomTab = function ($product) use ($isSuperAdmin) {
            $schema = [
                Section::make('Thiết lập chung')
                        ->schema([
                            Hidden::make('room_' . $product->id . '.product_id')
                                ->default($product->id),
                            TextInput::make('room_' . $product->id . '.product_name')
                                ->label('Tên Phòng')
                                ->default($product->name)
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('room_' . $product->id . '.price')
                                ->label('Giá mỗi đêm')
                                ->placeholder('VD: 500000')
                                ->suffix('VNĐ')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TimePicker::make('room_' . $product->id . '.default_checkin')
                                ->label('Giờ nhận phòng mặc định')
                                ->seconds(false)
                                ->native(false)
                                ->locale('vi')
                                ->helperText('Giờ check-in hiển thị trên form đặt theo ngày (VD: 14:00)')
                                ->timezone('Asia/Ho_Chi_Minh')
                                ->default('14:00'),
                            TimePicker::make('room_' . $product->id . '.default_checkout')
                                ->label('Giờ trả phòng mặc định')
                                ->seconds(false)
                                ->native(false)
                                ->locale('vi')
                                ->helperText('Giờ check-out hiển thị trên form đặt theo ngày (VD: 12:00)')
                                ->timezone('Asia/Ho_Chi_Minh')  
                                ->seconds(false)
                                ->default('12:00'),
                        ])->columns(2),

                    Section::make('Thiết lập cọc')
                        ->description('Cấu hình điều kiện và % tiền cọc khách phải thanh toán khi đặt phòng.')
                        ->schema([
                            TextInput::make('room_' . $product->id . '.deposit_min_nights')
                                ->label('Đặt từ X đêm mới cọc')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(30)
                                ->default(2)
                                ->suffix('đêm')
                                ->helperText('0 = luôn thanh toán 100%. 1 = đặt 1 đêm trở lên có cọc. 2 = từ 2 đêm mới cọc.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TextInput::make('room_' . $product->id . '.deposit_multi_night')
                                ->label('Phần trăm cọc (%)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->default(50)
                                ->suffix('%')
                                ->helperText('Ví dụ: 50 → khách cọc 50%, mã cổng gửi sau khi thanh toán phần còn lại.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                        ])->columns(2),

                    Section::make('Cấu hình phòng')
                        ->description('Quy định số khách tối đa miễn phí và phụ thu khi có thêm người.')
                        ->schema([
                            TextInput::make('room_' . $product->id . '.room_config_max_free_guests')
                                ->label('Số khách tối đa (miễn phụ thu)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(10)
                                ->default(2)
                                ->suffix('người')
                                ->helperText('Ví dụ: 2 → từ khách thứ 3 trở đi mới tính phụ thu.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TextInput::make('room_' . $product->id . '.room_config_extra_guest_fee')
                                ->label('Phụ thu mỗi người thêm')
                                ->inputMode('numeric')
                                ->default(50000)
                                ->suffix('VNĐ')
                                ->helperText('Số tiền phụ thu cho mỗi người vượt quá số tối đa miễn phí.')
                                ->dehydrateStateUsing(fn ($state) => (int) preg_replace('/[^0-9]/', '', (string)($state ?? '0'))),
                        ])->columns(2),

            ];

            if ($isSuperAdmin) {
                $schema[] = Section::make('Ngày đặc biệt & Khuyến mãi')
                    ->description('Nhấn vào ô ngày để thiết lập giá và khuyến mãi. Ưu tiên cao hơn giá gốc của phòng.')
                    ->schema([
                        DatePriceCalendarField::make('room_' . $product->id . '.dateTimeSlots')
                            ->roomId($product->id)
                            ->basePrice((int) ($product->price ?? 0))
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
        $preselectId    = request()->query('product_id');

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

                    // Style=2: lưu dateTimeSlots → room_time_slots qua timeslot type=date
                    if ($productStyle === 2 && isset($roomData['dateTimeSlots']) && is_array($roomData['dateTimeSlots'])) {
                        $existingDateIds = collect($roomData['dateTimeSlots'])->pluck('id')->filter()->toArray();
                        $dateSlotsToDelete = RoomTimeSlot::where('room_id', $productId)
                            ->whereHas('timeslot', fn($q) => $q->where('type', 'date'))
                            ->when(!empty($existingDateIds), fn($q) => $q->whereNotIn('id', $existingDateIds))
                            ->get();

                        foreach ($dateSlotsToDelete as $s) {
                            $s->promotions()->detach();
                            $s->delete();
                        }

                        $changedDates = [];

                        foreach ($roomData['dateTimeSlots'] as $slot) {
                            if (empty($slot['date'])) continue;

                            $dateLabel = \Carbon\Carbon::parse($slot['date'])->format('Y-m-d');
                            $changedDates[] = $dateLabel;
                            $timeSlot  = TimeSlot::firstOrCreate(
                                ['label' => $dateLabel, 'type' => 'date'],
                                ['start_time' => null, 'end_time' => null]
                            );

                            $dateSlotData = [
                                'room_id'     => $productId,
                                'timeslot_id' => $timeSlot->id,
                                'price'       => $slot['price'] !== null
                                    ? (int) str_replace(['.', ','], '', $slot['price'])
                                    : null,
                                'status'      => 'available',
                                'checkin'     => $slot['checkin'] ?? null,
                                'checkout'    => $slot['checkout'] ?? null,
                            ];

                            $dateSlotModel = null;
                            if (!empty($slot['id'])) {
                                $dateSlotModel = RoomTimeSlot::find($slot['id']);
                                if ($dateSlotModel) $dateSlotModel->update($dateSlotData);
                            } else {
                                $dateSlotModel = RoomTimeSlot::create($dateSlotData);
                            }

                            if ($dateSlotModel) {
                                $allPromoIds = array_merge(
                                    array_map('intval', $slot['promotions'] ?? []),
                                    array_map('intval', $slot['surcharges'] ?? [])
                                );
                                $dateSlotModel->promotions()->sync($allPromoIds);
                                $dateSlotModel->coupons()->sync(array_map('intval', $slot['coupons'] ?? []));
                            }
                        }

                        $this->broadcastDatesChanged($productId, $changedDates);
                    }

                    // Style=1: lưu roomTimeSlots (timeslot_id NOT NULL)
                    if ($productStyle === 1 && isset($roomData['roomTimeSlots']) && is_array($roomData['roomTimeSlots'])) {
                        $existingIds = collect($roomData['roomTimeSlots'])->pluck('id')->filter()->toArray();
                        $slotsToDelete = RoomTimeSlot::where('room_id', $productId)
                            ->when(!empty($existingIds), fn($q) => $q->whereNotIn('id', $existingIds))
                            ->get();

                        foreach($slotsToDelete as $slotToDelete) {
                            $slotToDelete->promotions()->detach();
                            $slotToDelete->delete();
                        }

                        foreach ($roomData['roomTimeSlots'] as $slot) {
                            $slotData = [
                                'room_id' => $productId,
                                'timeslot_id' => $slot['timeslot_id'],
                                'price' => (int) str_replace(['.', ','], '', $slot['price']),
                                'over_night' => $slot['over_night'] ?? false,
                                'status' => $slot['status'] ?? 'available',
                            ];

                            $roomTimeSlotModel = null;

                            if (!empty($slot['id'])) {
                                $roomTimeSlotModel = RoomTimeSlot::find($slot['id']);
                                if ($roomTimeSlotModel) {
                                    $roomTimeSlotModel->update($slotData);
                                }
                            } else {
                                $roomTimeSlotModel = RoomTimeSlot::create($slotData);
                            }

                            if ($roomTimeSlotModel) {
                                $roomTimeSlotModel->promotions()->sync($slot['promotions'] ?? []);
                            }
                        }
                    }
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

    /**
     * Ghi đè giá hàng loạt cho một khoảng ngày.
     * Được gọi từ Alpine.js trong blade: $wire.call('applyBulkOverride', ...)
     */
    public function applyBulkOverride(
        string  $roomId,
        string  $startDate,
        string  $endDate,
        int     $price,
        ?string $checkinTime,
        ?string $checkoutTime
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $count = 0;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            RoomTimeSlot::updateOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => $price, 'status' => 'available', 'checkin' => $checkinTime, 'checkout' => $checkoutTime]
            );

            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        // Reload form state để calendar cập nhật ngay
        $this->fillFormData();

        Notification::make()
            ->title("Đã áp dụng giá cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()
            ->send();
    }

    /**
     * Lưu 1 ngày đơn lẻ trực tiếp (gọi từ Alpine saveDate).
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
        $hasData = $price !== null || !empty($promoIds) || !empty($surchargeIds) || !empty($couponIds);
        if (! $hasData) {
            return;
        }

        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $timeSlot = TimeSlot::firstOrCreate(
            ['label' => $dateLabel, 'type' => 'date'],
            ['start_time' => null, 'end_time' => null]
        );

        $slotModel = RoomTimeSlot::updateOrCreate(
            ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
            ['price' => $price, 'status' => 'available', 'checkin' => $checkin, 'checkout' => $checkout]
        );

        $allPromoIds = array_merge(
            array_map('intval', $promoIds),
            array_map('intval', $surchargeIds)
        );
        $slotModel->promotions()->sync($allPromoIds);
        $slotModel->coupons()->sync(array_map('intval', $couponIds));

        $this->broadcastDatesChanged($roomId, [$dateLabel]);

        $this->fillFormData();

        Notification::make()
            ->title('Đã lưu ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()
            ->send();
    }

    public function applyBulkPromotion(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $promotionIds
    ): void {
        $product = Product::find($roomId);

        if (!$product) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $count = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            // firstOrCreate: nếu slot đã có → giữ nguyên giá ghi đè
            //                nếu chưa có → tạo mới với price=null (không ghi đè, dùng giá gốc phòng)
            $slotModel = RoomTimeSlot::firstOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => null, 'status' => 'available']
            );

            $slotModel->promotions()->sync($promotionIds);
            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        $this->fillFormData();

        Notification::make()
            ->title("Đã áp dụng khuyến mãi cho {$count} ngày")
            ->body(
                \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                . ' → '
                . \Carbon\Carbon::parse($endDate)->format('d/m/Y')
            )
            ->success()
            ->send();
    }

    /**
     * Gỡ ghi đè hàng loạt: xóa price, checkin, checkout trong khoảng ngày.
     * Promotion giữ nguyên. Slot không có promotion → xóa hẳn record.
     */
    public function removeBulkOverride(
        string $roomId,
        string $startDate,
        string $endDate
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if ($slot->promotions->isEmpty()) {
                // Không có promotion → xóa hẳn slot
                $slot->delete();
            } else {
                // Có promotion → giữ slot nhưng xóa giá ghi đè & checkin/checkout
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
            }
            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        $this->fillFormData();

        Notification::make()
            ->title("Đã gỡ ghi đè cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Xóa ghi đè của 1 ngày đơn lẻ.
     * Logic: slot có promotion → chỉ xóa price/checkin/checkout; không có → xóa hẳn record.
     */
    public function deleteSingleDate(string $roomId, string $date): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with('promotions')
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            if ($slot->promotions->isEmpty()) {
                $slot->delete();
            } else {
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
            }
        }

        $this->broadcastDatesChanged($roomId, [$dateLabel]);

        $this->fillFormData();

        Notification::make()
            ->title('Đã xóa ghi đè ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ khuyến mãi hàng loạt cho khoảng ngày.
     * $promoIds rỗng = gỡ tất cả khuyến mãi.
     */
    public function removeBulkPromotion(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $promoIds = []
    ): void {
        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if (empty($promoIds)) {
                $slot->promotions()->detach();
            } else {
                $slot->promotions()->detach($promoIds);
            }

            $slot->refresh();
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        $this->fillFormData();

        Notification::make()
            ->title("Đã gỡ khuyến mãi cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ mã giảm giá hàng loạt cho khoảng ngày.
     * $couponIds rỗng = gỡ tất cả mã giảm giá.
     */
    public function removeBulkCoupon(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $couponIds = []
    ): void {
        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if (empty($couponIds)) {
                $slot->coupons()->detach();
            } else {
                $slot->coupons()->detach($couponIds);
            }

            $slot->refresh();
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        $this->fillFormData();

        Notification::make()
            ->title("Đã gỡ mã giảm giá cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ 1 khuyến mãi / phụ thu khỏi 1 ngày đơn lẻ.
     * Dùng chung cho cả KM (percentage/fixed) và phụ thu (increase_*).
     */
    public function removeSinglePromotion(string $roomId, string $date, int $promotionId): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with(['promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            $slot->promotions()->detach($promotionId);
            $slot->refresh();
            // Slot rỗng (không còn KM, coupon và không có giá ghi đè) → xóa hẳn
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
        }

        $this->broadcastDatesChanged($roomId, [$dateLabel]);

        $this->fillFormData();

        Notification::make()
            ->title('Đã gỡ khuyến mãi ngày ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ 1 mã giảm giá khỏi 1 ngày đơn lẻ.
     */
    public function removeSingleCoupon(string $roomId, string $date, int $couponId): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with(['promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            $slot->coupons()->detach($couponId);
            $slot->refresh();
            // Slot rỗng → xóa hẳn
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
        }

        $this->broadcastDatesChanged($roomId, [$dateLabel]);

        $this->fillFormData();

        Notification::make()
            ->title('Đã gỡ mã giảm giá ngày ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Xóa tất cả ngày đặc biệt của phòng.
     */
    public function deleteAllDates(string $roomId): void    {
        $slots = RoomTimeSlot::with(['promotions', 'timeslot'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date'))
            ->get();

        $deletedDates = $slots->pluck('timeslot.label')->filter()->all();

        foreach ($slots as $slot) {
            $slot->promotions()->detach();
            $slot->delete();
        }

        $this->broadcastDatesChanged($roomId, $deletedDates);

        $this->fillFormData();

        Notification::make()
            ->title('Đã xóa tất cả ngày đặc biệt')
            ->success()->send();
    }

    /**
     * Áp mã giảm giá hàng loạt cho khoảng ngày.
     * Lưu vào pivot coupon_room_time_slot.
     */
    public function applyBulkCoupon(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $couponIds
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $basePrice = (int) ($product->price ?? 0);
        $count = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            // Tạo slot nếu chưa có (giữ giá ghi đè nếu đã có)
            $slotModel = RoomTimeSlot::firstOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => null, 'status' => 'available']
            );

            // Sync coupons vào pivot coupon_room_time_slot
            foreach ($couponIds as $couponId) {
                $slotModel->coupons()->syncWithoutDetaching([$couponId]);
            }

            $count++;
        }

        $this->broadcastDatesChanged($roomId, $this->dateRange($start, $end));

        $this->fillFormData();

        Notification::make()
            ->title("Đã áp mã giảm giá cho {$count} ngày")
            ->body(
                \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                . ' → '
                . \Carbon\Carbon::parse($endDate)->format('d/m/Y')
            )
            ->success()->send();
    }
}