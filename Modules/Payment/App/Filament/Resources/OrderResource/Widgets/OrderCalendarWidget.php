<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Widgets;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Guava\Calendar\Actions\ViewAction;
use Guava\Calendar\Widgets\CalendarWidget;
use Guava\Calendar\ValueObjects\Event;
use App\ValueObjects\CustomCalendarEvent;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\App\Filament\Resources\OrderResource;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use Modules\Category\Entities\Category;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Carbon\Carbon;

class OrderCalendarWidget extends CalendarWidget
{
    // Cấu hình view mặc định
    protected string $calendarView = 'resourceTimelineWeek';

    // Timezone
    protected ?string $timezone = 'Asia/Ho_Chi_Minh';

    // Cho phép click vào event
    protected bool $eventClickEnabled = true;

    // QUAN TRỌNG: Cấu hình các view có sẵn
    // Người dùng có thể chuyển đổi giữa các view này
    protected array $availableViews = [
        'dayGridMonth',           // Tháng dạng lưới
        'timeGridWeek',          // Tuần dạng lưới thời gian
        'timeGridDay',           // Ngày dạng lưới thời gian
        'listWeek',              // Danh sách theo tuần
        'resourceTimelineDay',   // Timeline theo ngày (có resource)
        'resourceTimelineWeek',  // Timeline theo tuần (có resource)
        'resourceTimelineMonth', // Timeline theo tháng (có resource)
    ];

    // Chiều cao calendar
    protected ?string $contentHeight = '600px';

    // Hiển thị số tuần
    protected bool $weekNumbers = true;

    // Cho phép kéo thả event
    protected bool $eventDragEnabled = false;

    // Cho phép resize event
    protected bool $eventResizeEnabled = false;

    // Slot duration (khoảng thời gian mỗi ô)
    protected string $slotDuration = '01:00:00';

    // Giờ bắt đầu hiển thị
    protected string $slotMinTime = '00:00:00';

    // Giờ kết thúc hiển thị
    protected string $slotMaxTime = '24:00:00';

    // Ngày bắt đầu tuần (0: Chủ nhật, 1: Thứ 2)
    protected int $firstDay = 1;

    // Business hours (giờ làm việc)
    protected array $businessHours = [
        'daysOfWeek' => [1, 2, 3, 4, 5, 6, 0], // Tất cả các ngày
        'startTime' => '00:00',
        'endTime' => '24:00',
    ];

    public function getHeading(): string|HtmlString
    {
        return new HtmlString('
        <style>
            .ec-event-time { display: none !important; }
            
            /* Custom calendar styling */
            .fc-toolbar-title {
                font-size: 1.5rem !important;
                font-weight: 600;
            }
            
            .fc-button {
                text-transform: capitalize !important;
            }
            
            /* Highlight today */
            .fc-day-today {
                background-color: rgba(59, 130, 246, 0.1) !important;
            }
            .ec-main,
.ec-body,
.ec-content {
  overflow-x: auto !important;
  overflow-y: auto !important;
}

/* Hiển thị scrollbar trên Chrome/Safari */
.ec-main::-webkit-scrollbar,
.ec-body::-webkit-scrollbar {
  height: 12px;
  width: 12px;
}

.ec-main::-webkit-scrollbar-track,
.ec-body::-webkit-scrollbar-track {
  background: #f1f1f1;
}

.ec-main::-webkit-scrollbar-thumb,
.ec-body::-webkit-scrollbar-thumb {
  background: #888;
  border-radius: 6px;
}

.ec-main::-webkit-scrollbar-thumb:hover,
.ec-body::-webkit-scrollbar-thumb:hover {
  background: #555;
}

/* Firefox */
.ec-main,
.ec-body {
  scrollbar-width: thin;
  scrollbar-color: #888 #f1f1f1;
}
        </style>
        <div style="display: flex; align-items: center; gap: 8px;">
           <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-calendar-icon lucide-calendar">
               <path d="M8 2v4"/><path d="M16 2v4"/>
               <rect width="18" height="18" x="3" y="4" rx="2"/>
               <path d="M3 10h18"/>
           </svg>
           <span style="font-weight: 600; font-size: 1.125rem;">Các đơn đặt phòng - Calendar 365Home</span>
        </div>');
    }

    private function branchFilteredOrderQuery(): Builder
    {
        $query = Order::query();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();

        if (empty($allCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }

    public function getEvents(array $fetchInfo = []): Collection|array
    {
        $query = $this->branchFilteredOrderQuery()
            ->with(['items.product'])
            ->whereIn('status', ['paid', 'faild', 'pending', 'deposit']);

        // Nếu có fetchInfo từ calendar (khi người dùng thay đổi view/tháng)
        if (!empty($fetchInfo['start']) && !empty($fetchInfo['end'])) {
            $start = Carbon::parse($fetchInfo['start'])->startOfDay();
            $end = Carbon::parse($fetchInfo['end'])->endOfDay();

            // Lọc orders có checkin/checkout trong khoảng thời gian hiển thị
            $query->whereHas('items', function ($q) use ($start, $end) {
                $q->where(function ($subQ) use ($start, $end) {
                    $subQ->whereBetween('checkin_date', [$start, $end])
                        ->orWhereBetween('checkout_date', [$start, $end])
                        ->orWhere(function ($dateQ) use ($start, $end) {
                            $dateQ->where('checkin_date', '<=', $start)
                                ->where('checkout_date', '>=', $end);
                        });
                });
            });
        }

        return $query->get()
            ->flatMap(function (Order $order) {
                return $order->items
                    ->filter(fn($item) => !is_null($item->product_id))
                    ->map(function ($item) use ($order) {
                    $start = Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        $item->getRawOriginal('checkin_date'),
                        'Asia/Ho_Chi_Minh'
                    );

                    $end = Carbon::createFromFormat(
                        'Y-m-d H:i:s',
                        $item->getRawOriginal('checkout_date'),
                        'Asia/Ho_Chi_Minh'
                    );

                    $colors = $this->getStatusColors($order->status);

                    return CustomCalendarEvent::make($order)
                        ->title($order->buyer_name ?? 'Đơn #' . $order->id)
                        ->start($start)
                        ->end($end)
                        ->resourceId($item->product_id)
                        ->backgroundColor($colors['background'])
                        ->textColor($colors['text'])
                        ->borderColor($colors['background'])
                        ->extendedProps([
                            'id' => $order->id,
                            'order_code' => $order->order_code,
                            'phone' => $order->buyer_phone,
                            'guest_count' => $item->guest_count,
                            'slot_label' => $item->slot_label,
                            'product_name' => $item->name,
                            'price' => $item->price,
                            'status' => $order->status,
                        ]);
                });
            });
    }

    protected function getStatusColors(string $status): array
    {
        return match($status) {
            'paid' => [
                'background' => '#22c55e', // Green
                'text' => '#ffffff'
            ],
            'deposit' => [
                'background' => '#f59e0b', // Amber
                'text' => '#ffffff'
            ],
            'pending' => [
                'background' => '#eab308', // Yellow
                'text' => '#ffffff'
            ],
            'faild' => [
                'background' => '#ef4444', // Red
                'text' => '#ffffff'
            ],
            default => [
                'background' => '#6b7280', // Gray
                'text' => '#ffffff'
            ]
        };
    }

    public function getResources(): Collection|array
    {
        $query = Product::where('is_activated', true)->orderBy('name');

        $user = auth()->user();

        if ($user && ! $user->isSuperAdmin()) {
            $allCategoryIds = $user->allowedCategoryIds();

            if (empty($allCategoryIds)) {
                return collect();
            }

            $query->whereHas('categories', function (Builder $q) use ($allCategoryIds) {
                $q->whereIn('categories.id', $allCategoryIds);
            });
        }

        return $query->get()->map->toCalendarResource();
    }

    // Context menu khi click vào event
    public function getEventClickContextMenuActions(): array
    {
        return [
            $this->viewAction(),
            $this->editAction(),
        ];
    }

    // Action xem chi tiết
    public function viewAction(): Action
    {
        return ViewAction::make()
            ->label('Xem chi tiết')
            ->icon('heroicon-m-eye')
            ->modalWidth('7xl')
            ->modalHeading(fn($record) => 'Chi tiết đơn hàng: ' . ($record->order_code ?? '#' . $record->id))
            ->record(fn($arguments) => Order::with(['items.product', 'accessCodes'])->find($arguments['event']['extendedProps']['id'] ?? null))
            ->form([
                Tabs::make('OrderTabs')
                    ->tabs([
                        // TAB 1: THÔNG TIN KHÁCH HÀNG
                        Tabs\Tab::make('Thông tin khách hàng')
                            ->icon('heroicon-m-user')
                            ->schema([
                                Section::make('Thông tin khách hàng')
                                    ->icon('heroicon-m-identification')
                                    ->iconColor('primary')
                                    ->collapsible()
                                    ->schema([
                                        Grid::make(3)->schema([
                                            TextInput::make('buyer_name')
                                                ->label('Tên khách hàng')
                                                ->disabled(),

                                            TextInput::make('buyer_phone')
                                                ->label('Số điện thoại')
                                                ->disabled(),

                                            TextInput::make('guest_count')
                                                ->label('Số lượng khách')
                                                ->disabled(),
                                        ]),

                                        Textarea::make('description')
                                            ->label('Ghi chú của khách')
                                            ->disabled()
                                            ->rows(4)
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('CCCD/CMND')
                                    ->icon('heroicon-m-credit-card')
                                    ->iconColor('primary')
                                    ->collapsible()
                                    ->schema([
                                        Grid::make(2)->schema([
                                            FileUpload::make('cccd_front')
                                                ->label('CCCD/CMND mặt trước')
                                                ->image()
                                                ->imagePreviewHeight('250')
                                                ->disabled(),

                                            FileUpload::make('cccd_back')
                                                ->label('CCCD/CMND mặt sau')
                                                ->image()
                                                ->imagePreviewHeight('250')
                                                ->disabled(),
                                        ]),
                                    ]),
                            ]),

                        // TAB 2: THÔNG TIN ĐẶT PHÒNG
                        Tabs\Tab::make('Thông tin đơn đặt phòng')
                            ->icon('heroicon-m-home-modern')
                            ->schema([
                                Section::make('Mã cổng')
                                    ->schema([
                                        Placeholder::make('access_code_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record || !$record->accessCode) {
                                                    return 'Chưa có mã cổng';
                                                }

                                                $code = $record->accessCode;

                                                return view('payment::components.access-code-info', [
                                                    'code' => $code,
                                                ]);
                                            }),
                                    ])
                                    ->visible(fn($record) => $record && $record->status === 'paid')
                                    ->collapsible(),

                                Grid::make(2)->schema([
                                    Section::make('Chi tiết đặt phòng')
                                        ->icon('heroicon-m-calendar-days')
                                        ->iconColor('primary')
                                        ->schema([
                                            Grid::make(2)->schema([
                                                TextInput::make('order_code')
                                                    ->label('Mã đơn hàng')
                                                    ->disabled(),

                                                Select::make('category_id')
                                                    ->label('Chi nhánh')
                                                    ->options(Category::pluck('name', 'id'))
                                                    ->disabled(),
                                            ]),

                                            Repeater::make('orderItems')
                                                ->relationship('items')
                                                ->schema([
                                                    Grid::make(2)->schema([
                                                        Select::make('product_id')
                                                            ->label('Phòng')
                                                            ->options(Product::query()->pluck('name', 'id'))
                                                            ->disabled(),

                                                        TextInput::make('guest_count')
                                                            ->label('Số lượng khách')
                                                            ->disabled(),
                                                    ]),

                                                    TextInput::make('price')
                                                        ->label('Giá')
                                                        ->disabled(),

                                                    TextInput::make('extra_fee')
                                                        ->label('Phụ thu')
                                                        ->disabled(),

                                                    Grid::make(2)->schema([
                                                        DateTimePicker::make('checkin_date')
                                                            ->label('Ngày & Giờ nhận phòng')
                                                            ->displayFormat('d/m/Y H:i')
                                                            ->disabled(),

                                                        DateTimePicker::make('checkout_date')
                                                            ->label('Ngày & Giờ trả phòng')
                                                            ->displayFormat('d/m/Y H:i')
                                                            ->disabled(),
                                                    ]),
                                                ])
                                                ->columns(2)
                                                ->label('Danh sách phòng đặt')
                                                ->collapsible()
                                                ->itemLabel(fn($state) => !empty($state['name']) ? $state['name'] : 'Phòng')
                                                ->addable(false)
                                                ->deletable(false)
                                                ->reorderable(false),
                                        ])
                                        ->columnSpan(5),

                                    Section::make('Tổng thanh toán')
                                        ->icon('heroicon-s-currency-dollar')
                                        ->iconColor('primary')
                                        ->schema([
                                            Placeholder::make('amount')
                                                ->label('')
                                                ->content(function (Get $get, $record) {
                                                    $total = 0;
                                                    $items = $get('orderItems');

                                                    if (is_array($items)) {
                                                        $total = collect($items)->sum(function ($item) {
                                                            $price = isset($item['price']) ? (float)$item['price'] : 0;
                                                            $extraFee = isset($item['extra_fee']) ? (float)$item['extra_fee'] : 0;
                                                            return $price + $extraFee;
                                                        });
                                                    }

                                                    return view('payment::components.total-amount-card', [
                                                        'totalAmount' => $total,
                                                        'items' => $items,
                                                    ]);
                                                }),
                                        ])
                                        ->columnSpan(2),
                                ])->columns(7),
                            ]),

                        // TAB 3: THÔNG TIN THANH TOÁN
                        Tabs\Tab::make('Thông tin thanh toán')
                            ->icon('heroicon-m-credit-card')
                            ->schema([
                                Section::make('Phương thức thanh toán')
                                    ->icon('heroicon-m-banknotes')
                                    ->iconColor('primary')
                                    ->schema([
                                        Grid::make(3)->schema([
                                            Select::make('payment_method')
                                                ->label('Phương thức thanh toán')
                                                ->options([
                                                    'cod' => 'Tiền mặt',
                                                    'PayOS' => 'PayOS',
                                                ])
                                                ->disabled(),

                                            Select::make('status')
                                                ->label('Trạng thái thanh toán')
                                                ->options([
                                                    'pending' => 'Chờ thanh toán',
                                                    'paid' => 'Đã thanh toán',
                                                    'failed' => 'Hủy thanh toán',
                                                ])
                                                ->disabled(),

                                            TextInput::make('amount')
                                                ->label('Tổng tiền')
                                                ->disabled()
                                                ->prefix('VNĐ'),
                                        ]),

                                        Textarea::make('note_for_admin')
                                            ->label('Ghi chú dành cho nội bộ')
                                            ->disabled()
                                            ->rows(4)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(3),
                            ]),
                    ])
                    ->columnSpanFull()
            ])
            ->modalFooterActions([
                Action::make('edit')
                    ->label('Chỉnh sửa')
                    ->color('primary')
                    ->url(fn($record) => OrderResource::getUrl('edit', ['record' => $record->id]))
                    ->icon('heroicon-m-pencil-square'),

                Action::make('close')
                    ->label('Đóng')
                    ->color('gray')
                    ->action(fn() => null)
                    ->close(),
            ]);
    }

    // Action chỉnh sửa nhanh
    public function editAction(): Action
    {
        return Action::make('edit')
            ->label('Chỉnh sửa')
            ->icon('heroicon-m-pencil-square')
            ->color('warning')
            ->action(function ($arguments) {
                $orderId = $arguments['event']['extendedProps']['id'] ?? null;
                if ($orderId) {
                    redirect(OrderResource::getUrl('edit', ['record' => $orderId]));
                }
            });
    }

    public function authorize($ability, $arguments = [])
    {
        return true;
    }
    public function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            'locale' => 'vi',
            'headerToolbar' => [
                'start' => 'prev,next today',
                'center' => 'title',
                'end' => 'dayGridMonth,timeGridWeek,timeGridDay,resourceTimelineWeek'
            ],
            'buttonText' => [
                'today' => 'Hôm nay',
                'month' => 'Tháng',
                'week' => 'Tuần',
                'day' => 'Ngày',
                'list' => 'Danh sách',
                'dayGridMonth' => 'Lưới tháng',
                'timeGridWeek' => 'Lưới tuần',
                'timeGridDay' => 'Lưới ngày',
                'resourceTimelineDay' => 'Timeline ngày',
                'resourceTimelineWeek' => 'Timeline tuần',
                'resourceTimelineMonth' => 'Timeline tháng',
            ],
            'allDayText' => 'Cả ngày',
            'noEventsText' => 'Không có đơn đặt phòng',
            'dayHeaderFormat' => [
                'weekday' => 'short',
                'day' => 'numeric',
                'month' => 'numeric',
            ],
            'slotLabelFormat' => [
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,
            ],
        ]);
    }
}