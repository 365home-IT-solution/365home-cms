<?php

namespace Modules\Payment\App\Filament\Resources\OrderResource\Forms;

use Dom\Text;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Modules\AuditLog\Entities\AuditLog;
use Modules\AuditLog\Services\AuditLogger;
use PayOS\PayOS;
use Modules\Product\App\Models\Product;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\FileUpload;
use Filament\Support\Colors\Color;
use Modules\Category\Entities\Category;
use Modules\Category\Entities\Categorizable;

class OrderForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('OrderTabs')
                    ->tabs([
                        // TAB 1: THÔNG TIN KHÁCH HÀNG
                        Tabs\Tab::make(__('payment::order.form.fieldset.buyer_info'))
                            ->icon('heroicon-m-user')
                            ->badge(fn(Get $get) => self::getBuyerInfoBadge($get))
                            ->schema([
                                Section::make('Thông tin khách hàng')
                                    ->icon('heroicon-m-identification')
                                    ->iconColor('primary')
                                    ->collapsible()
                                    ->schema([
                                        Grid::make(3)->schema([
                                            TextInput::make('buyer_name')
                                                ->label('Tên khách hàng')
                                                ->placeholder('VD: Nguyễn Văn An')
                                                ->required()
                                                ->maxLength(255)
                                                ->hintIcon('heroicon-m-user-circle')
                                                ->hintColor('primary')
                                                ->hintIconTooltip('Họ tên đầy đủ như trong CCCD.')
                                                ->live(onBlur: true)
                                                ->validationMessages([
                                                    'required' => 'Vui lòng nhập họ tên khách hàng',
                                                    'max' => 'Họ tên không được quá 255 ký tự'
                                                ]),

                                            TextInput::make('buyer_phone')
                                                ->label(__('payment::order.form.label.buyer_phone'))
                                                ->placeholder('VD: 0912345678')
                                                ->tel()
                                                ->required()
                                                ->regex('/^[0-9]{10,11}$/')
                                                ->live(onBlur: true)
                                                ->hintIcon('heroicon-m-phone')
                                                ->hintColor('primary')
                                                ->hintIconTooltip('Số điện thoại để liên hệ xác nhận')
                                                ->validationMessages([
                                                    'required' => 'Vui lòng nhập số điện thoại',
                                                    'regex' => 'Số điện thoại không đúng định dạng (10-11 số)'
                                                ]),

                                            TextInput::make('guest_count')
                                                ->label('Số lượng khách')
                                                ->placeholder('Số lượng khách')
                                                ->prefixIcon('heroicon-o-users')
                                                ->helperText('Phụ thu 50.000đ/người từ khách thứ 3')
                                                ->numeric()
                                                ->minValue(1)
                                                ->default(1)
                                                ->required()
                                                ->hintIcon('heroicon-m-users')
                                                ->hintColor('primary')
                                                ->hintIconTooltip('Tối đa 4 người')
                                                ->live(onBlur: true),
                                        ]),

                                        Textarea::make('description')
                                            ->label(__('payment::order.form.label.description'))
                                            ->placeholder('VD: Yêu cầu phòng tầng cao, view đẹp...')
                                            ->nullable()
                                            ->default('')
                                            ->rows(4)
                                            ->maxLength(1000)
                                            ->hintIcon('heroicon-m-pencil-square')
                                            ->hintColor('primary')
                                            ->hintIconTooltip('Ghi chú thêm về yêu cầu đặc biệt của khách hàng')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Upload CCCD/CMND')
                                    ->description('Tải lên ảnh CCCD/CMND rõ nét để xác minh danh tính')
                                    ->icon('heroicon-m-credit-card')
                                    ->iconColor('primary')
                                    ->collapsible(false)
                                    ->collapsed()
                                    ->schema([
                                        Grid::make(2)->schema([
                                            FileUpload::make('cccd_front')
                                                ->label('CCCD/CMND mặt trước')
                                                ->helperText('Kích thước tối đa: 10MB. Upload ảnh gốc không crop/resize để quét QR được.')
                                                ->image()
                                                ->directory('cccd')
                                                ->imagePreviewHeight('250')
                                                ->loadingIndicatorPosition('center')
                                                ->panelAspectRatio('16:10')
                                                ->panelLayout('integrated')
                                                ->removeUploadedFileButtonPosition('top-right')
                                                ->uploadButtonPosition('center')
                                                ->uploadProgressIndicatorPosition('center')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                ->maxSize(10240)
                                                ->downloadable()
                                                ->nullable(),

                                            FileUpload::make('cccd_back')
                                                ->label('CCCD/CMND mặt sau')
                                                ->helperText('Kích thước tối đa: 10MB. Upload ảnh gốc không crop/resize để quét QR được.')
                                                ->image()
                                                ->directory('cccd')
                                                ->imagePreviewHeight('250')
                                                ->loadingIndicatorPosition('center')
                                                ->panelAspectRatio('16:10')
                                                ->panelLayout('integrated')
                                                ->removeUploadedFileButtonPosition('top-right')
                                                ->uploadButtonPosition('center')
                                                ->uploadProgressIndicatorPosition('center')
                                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/jpg', 'image/avif', 'image/webp', 'image/heic', 'image/heif'])
                                                ->maxSize(10240)
                                                ->downloadable()
                                                ->nullable(),
                                        ]),
                                    ]),
                            ]),

                        // TAB 2: THÔNG TIN ĐẶT PHÒNG
                        Tabs\Tab::make('Thông tin đơn đặt phòng')
                            ->icon('heroicon-m-home-modern')
                            ->badge(fn(Get $get) => self::getOrderInfoBadge($get))
                            ->badgeColor(fn(Get $get) => self::getOrderInfoBadgeColor($get))
                            ->schema([
                                Section::make('Mật khẩu phòng thủ công')
                                    ->icon('heroicon-o-key')
                                    ->iconColor('warning')
                                    ->description('Phòng này dùng khóa cơ, vui lòng cung cấp thông tin bên dưới cho khách.')
                                    ->schema([
                                        Placeholder::make('manual_lock_info')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (! $record) return '';

                                                $record->load(['items.product']);
                                                $firstItem = $record->items->sortBy('checkin_date')->first();
                                                $product   = $firstItem?->product;

                                                if (! $product) {
                                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-400 text-sm italic">Không tìm thấy thông tin phòng.</p>');
                                                }

                                                $checkinDate = $firstItem?->checkin_date
                                                    ? \Carbon\Carbon::parse($firstItem->checkin_date)
                                                    : null;

                                                $entry = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate(
                                                    $product,
                                                    $checkinDate
                                                );

                                                if (! $entry) {
                                                    return new \Illuminate\Support\HtmlString(
                                                        '<div class="flex items-center gap-2 text-warning-600 font-medium">'
                                                        . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/></svg>'
                                                        . 'Phòng được đánh dấu khóa thủ công nhưng chưa có mật khẩu. Vui lòng cấu hình trong <strong>Mật khẩu khóa thủ công</strong>.'
                                                        . '</div>'
                                                    );
                                                }

                                                $rows = '';

                                                $rows .= '
                                                    <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;">
                                                        <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Pass Cổng</div>
                                                        <div style="font-size:20px;font-weight:700;letter-spacing:4px;color:#111827;font-family:monospace;">'
                                                    . e($entry->gate_password)
                                                    . '</div>
                                                    </div>';

                                                if ($entry->room_password) {
                                                    $rows .= '
                                                        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;">
                                                            <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Pass Phòng</div>
                                                            <div style="font-size:20px;font-weight:700;letter-spacing:4px;color:#111827;font-family:monospace;">'
                                                        . e($entry->room_password)
                                                        . '</div>
                                                        </div>';
                                                }

                                                if ($entry->notes) {
                                                    $rows .= '
                                                        <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 0;">
                                                            <div style="width:120px;font-size:13px;color:#6b7280;font-weight:500;">Ghi chú</div>
                                                            <div style="font-size:13px;color:#374151;">' . nl2br(e($entry->notes)) . '</div>
                                                        </div>';
                                                }

                                                return new \Illuminate\Support\HtmlString(
                                                    '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;">'
                                                    . $rows
                                                    . '</div>'
                                                );
                                            }),
                                    ])
                                    ->visible(function ($record) {
                                        if (! $record || ! in_array($record->status, ['paid', 'deposit'])) {
                                            return false;
                                        }
                                        $product = $record->items->sortBy('checkin_date')->first()?->product;
                                        return $product?->has_manual_lock === true;
                                    })
                                    ->collapsible(),

                                Section::make('Mã cổng')
                                    ->schema([
                                        Placeholder::make('access_code_info')
                                            ->label('')
                                            ->content(function ($record) {
                                            if (!$record) {
                                                return 'Chưa có mã cổng';
                                            }

                                            $record->load('accessCodes');

                                            $code = $record->accessCodes->first();
                                            if (!$code) {
                                                return new \Illuminate\Support\HtmlString(
                                                    '<div class="flex items-center gap-2 text-warning-600 font-medium">'
                                                    . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/></svg>'
                                                    . 'Chưa có mã cổng — Nhấn nút <strong>Gán mã cổng</strong> ở đầu trang để gán.'
                                                    . '</div>'
                                                );
                                            }

                                            return view('payment::components.access-code-info', [
                                                'code' => $code,
                                                'order' => $record,
                                            ]);
                                        }),
                                    ])
                                    ->visible(fn($record) => $record && $record->status === 'paid' && ! ($record->items->sortBy('checkin_date')->first()?->product?->has_manual_lock))
                                    ->collapsible(),
                                Grid::make(2)
                                    ->schema([
                                        Section::make('Chi tiết đặt phòng')
                                            ->description('Thông tin về phòng và thời gian lưu trú')
                                            ->icon('heroicon-m-calendar-days')
                                            ->iconColor('primary')
                                            ->schema([
                                                Grid::make(1)
                                                    ->schema([
                                                        Select::make('category_id')
                                                            ->label('Chi nhánh')
                                                            ->options(function () {
                                                                $user  = auth()->user();
                                                                $query = Category::query()
                                                                    ->where('category_type', 'product')
                                                                    ->orderBy('name');
                                                                if ($user && ! $user->isSuperAdmin()) {
                                                                    $allowedIds = $user->allowedCategoryIds();
                                                                    if (empty($allowedIds)) return [];
                                                                    $query->whereIn('id', $allowedIds);
                                                                }
                                                                return $query->pluck('name', 'id');
                                                            })
                                                            ->searchable()
                                                            ->required()
                                                            ->preload()
                                                            ->native(false)
                                                            ->hintIcon('heroicon-m-star')
                                                            ->hintColor('primary')
                                                            ->hintIconTooltip('Chi nhánh đặt phòng'),
                                                    ]),

                                                Repeater::make('orderItems')
                                                    ->relationship('items')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                Select::make('product_id')
                                                                    ->label('Chọn phòng')
                                                                    ->options(function () {
                                                                        $user  = auth()->user();
                                                                        $query = Product::query()->where('is_activated', true);
                                                                        if ($user && ! $user->isSuperAdmin()) {
                                                                            $categoryIds = $user->allowedCategoryIds();
                                                                            if (empty($categoryIds)) return [];
                                                                            $allowedIds = Categorizable::where('categorizable_type', Product::class)
                                                                                ->whereIn('category_id', $categoryIds)
                                                                                ->distinct()
                                                                                ->pluck('categorizable_id');
                                                                            $query->whereIn('id', $allowedIds);
                                                                        }
                                                                        return $query->pluck('name', 'id');
                                                                    })
                                                                    ->searchable()
                                                                    ->preload()
                                                                    ->required()
                                                                    ->reactive()
                                                                    ->hintIcon('heroicon-s-home')
                                                                    ->hintColor('primary')
                                                                    ->hintIconTooltip('Chọn phòng muốn đặt')
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        if ($state) {
                                                                            $product = Product::find($state);
                                                                            if ($product) {
                                                                                $set('name', $product->name);
                                                                                $set('product_style', (int)($product->styles ?? 1));
                                                                                $set('price_per_night', (float)($product->price ?? 0));
                                                                                $set('price', $product->price);
                                                                                $displayPrice = $product->discount ?: $product->price;
                                                                                $set('discount', $displayPrice);
                                                                                // Reset date fields khi đổi phòng
                                                                                $set('checkin_day', null);
                                                                                $set('checkout_day', null);
                                                                                if (!$get('quantity')) {
                                                                                    $set('quantity', 1);
                                                                                }
                                                                                self::calculateTotal($get, $set);
                                                                            }
                                                                        }
                                                                    }),

                                                                TextInput::make('guest_count')
                                                                    ->label('Số lượng khách')
                                                                    ->placeholder('Số lượng khách')
                                                                    ->helperText('Phụ thu theo cấu hình phòng từ khách thứ 3')
                                                                    ->numeric()
                                                                    ->minValue(1)
                                                                    ->default(1)
                                                                    ->required()
                                                                    ->hintIcon('heroicon-c-user-plus')
                                                                    ->hintColor('primary')
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        self::calculateTotal($get, $set);
                                                                    }),
                                                            ]),
                                                            // Style 1: nhập giá thủ công
                                                            TextInput::make('price')
                                                                    ->label('Giá')
                                                                    ->live(100)
                                                                    ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2)
                                                                    ->hintIcon('heroicon-c-currency-dollar')
                                                                    ->hintColor('primary')
                                                                    ->hintIconTooltip('Giá phòng')
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        $set('price', preg_replace('/[^0-9]/', '', $state));
                                                                        self::calculateTotal($get, $set);
                                                                    }),

                                                            // Style 2: hiển thị tổng tính tự động
                                                            Placeholder::make('price_nights_summary')
                                                                ->label('Tổng tiền phòng')
                                                                ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                ->live()
                                                                ->content(function(Get $get) {
                                                                    $checkin  = $get('checkin_day') ?: $get('checkin_date');
                                                                    $checkout = $get('checkout_day') ?: $get('checkout_date');
                                                                    $ppn = (float)($get('price_per_night') ?? 0);
                                                                    if ($checkin && $checkout && $ppn > 0) {
                                                                        $nights = \Carbon\Carbon::parse($checkin)->diffInDays(\Carbon\Carbon::parse($checkout));
                                                                        if ($nights > 0) {
                                                                            $total = $nights * $ppn;
                                                                            return new \Illuminate\Support\HtmlString(
                                                                                '<div class="text-sm" style="color:var(--color-primary)">' .
                                                                                '<span class="font-bold text-base">' . number_format($total, 0, ',', '.') . 'đ</span>' .
                                                                                ' <span class="text-gray-400 font-normal">(' . $nights . ' đêm × ' . number_format($ppn, 0, ',', '.') . 'đ/đêm)</span>' .
                                                                                '</div>'
                                                                            );
                                                                        }
                                                                    }
                                                                    return new \Illuminate\Support\HtmlString('<span class="text-gray-400 italic text-xs">Chọn ngày nhận & trả phòng để tính giá</span>');
                                                                }),

                                                            TextInput::make('extra_fee')
                                                                    ->label('Phụ thu')
                                                                    ->placeholder('0')
                                                                    ->suffix('VNĐ')
                                                                    ->numeric()
                                                                    ->hintIcon('heroicon-m-plus-circle')
                                                                    ->hintColor('primary')
                                                                    ->hintIconTooltip('Phụ thu 50k/người từ khách thứ 3 hoặc phí khác')
                                                                    ->default(0)
                                                                    ->live(debounce: 400)
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        self::calculateTotal($get, $set);
                                                                    }),

                                                        // Style 1: Đặt theo khung giờ — hiển thị DateTimePicker
                                                        Grid::make(2)
                                                            ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2)
                                                            ->schema([
                                                                DateTimePicker::make('checkin_date')
                                                                    ->label('Ngày & Giờ nhận phòng')
                                                                    ->placeholder('Chọn ngày & giờ nhận phòng')
                                                                    ->displayFormat('d/m/Y H:i')
                                                                    ->seconds(false)
                                                                    ->hintIcon('heroicon-m-arrow-right-end-on-rectangle')
                                                                    ->hintColor('primary')
                                                                    ->hintIconTooltip('Thời gian nhận phòng dự kiến')
                                                                    ->native(false)
                                                                    ->required(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2),

                                                                DateTimePicker::make('checkout_date')
                                                                    ->label('Ngày & Giờ trả phòng')
                                                                    ->placeholder('Chọn ngày & giờ trả phòng')
                                                                    ->displayFormat('d/m/Y H:i')
                                                                    ->seconds(false)
                                                                    ->hintIcon('heroicon-m-arrow-right-start-on-rectangle')
                                                                    ->hintColor('primary')
                                                                    ->hintIconTooltip('Thời gian trả phòng dự kiến')
                                                                    ->native(false)
                                                                    ->required(fn(Get $get) => (int)($get('product_style') ?? 1) !== 2),
                                                            ]),

                                                        // Style 2: Đặt theo ngày (tính đêm)
                                                        Grid::make(3)
                                                            ->visible(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                            ->schema([
                                                                DatePicker::make('checkin_day')
                                                                    ->label('Ngày nhận phòng')
                                                                    ->placeholder('Chọn ngày nhận phòng')
                                                                    ->displayFormat('d/m/Y')
                                                                    ->native(false)
                                                                    ->hintIcon('heroicon-m-arrow-right-end-on-rectangle')
                                                                    ->hintColor('primary')
                                                                    ->dehydrated(false)
                                                                    ->required(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                    ->afterStateHydrated(function ($component, Get $get) {
                                                                        $val = $get('checkin_date');
                                                                        if ($val) {
                                                                            $component->state(\Carbon\Carbon::parse($val)->format('Y-m-d'));
                                                                        }
                                                                    })
                                                                    ->live()
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        if ($state) {
                                                                            $set('checkin_date', $state);
                                                                            $checkout = $get('checkout_day');
                                                                            if ($checkout && \Carbon\Carbon::parse($state)->lt(\Carbon\Carbon::parse($checkout))) {
                                                                                $nights = \Carbon\Carbon::parse($state)->diffInDays(\Carbon\Carbon::parse($checkout));
                                                                                $pricePerNight = (float)($get('price_per_night') ?? 0);
                                                                                if ($pricePerNight > 0 && $nights > 0) {
                                                                                    $set('price', $pricePerNight * $nights);
                                                                                    self::calculateTotal($get, $set);
                                                                                }
                                                                            }
                                                                        }
                                                                    }),

                                                                DatePicker::make('checkout_day')
                                                                    ->label('Ngày trả phòng')
                                                                    ->placeholder('Chọn ngày trả phòng')
                                                                    ->displayFormat('d/m/Y')
                                                                    ->native(false)
                                                                    ->hintIcon('heroicon-m-arrow-right-start-on-rectangle')
                                                                    ->hintColor('primary')
                                                                    ->dehydrated(false)
                                                                    ->required(fn(Get $get) => (int)($get('product_style') ?? 1) === 2)
                                                                    ->afterStateHydrated(function ($component, Get $get) {
                                                                        $val = $get('checkout_date');
                                                                        if ($val) {
                                                                            $component->state(\Carbon\Carbon::parse($val)->format('Y-m-d'));
                                                                        }
                                                                    })
                                                                    ->live()
                                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                                        if ($state) {
                                                                            $set('checkout_date', $state);
                                                                            $checkin = $get('checkin_day');
                                                                            if ($checkin && \Carbon\Carbon::parse($checkin)->lt(\Carbon\Carbon::parse($state))) {
                                                                                $nights = \Carbon\Carbon::parse($checkin)->diffInDays(\Carbon\Carbon::parse($state));
                                                                                $pricePerNight = (float)($get('price_per_night') ?? 0);
                                                                                if ($pricePerNight > 0 && $nights > 0) {
                                                                                    $set('price', $pricePerNight * $nights);
                                                                                    self::calculateTotal($get, $set);
                                                                                }
                                                                            }
                                                                        }
                                                                    }),

                                                                Placeholder::make('nights_display')
                                                                    ->label('Số đêm & Tổng')
                                                                    ->live()
                                                                    ->content(function (Get $get) {
                                                                        $checkin = $get('checkin_day');
                                                                        $checkout = $get('checkout_day');
                                                                        if ($checkin && $checkout) {
                                                                            $c = \Carbon\Carbon::parse($checkin);
                                                                            $o = \Carbon\Carbon::parse($checkout);
                                                                            if ($c->gte($o)) {
                                                                                return new \Illuminate\Support\HtmlString('<span class="text-danger-600 text-xs">Ngày trả phải sau ngày nhận</span>');
                                                                            }
                                                                            $nights = $c->diffInDays($o);
                                                                            $pricePerNight = (float)($get('price_per_night') ?? 0);
                                                                            $total = $pricePerNight * $nights;
                                                                            return new \Illuminate\Support\HtmlString(
                                                                                '<div class="text-sm font-bold" style="color:var(--color-primary)">' .
                                                                                $nights . ' đêm' .
                                                                                ($pricePerNight > 0 ? ' × ' . number_format($pricePerNight, 0, ',', '.') . 'đ = <span class="text-base">' . number_format($total, 0, ',', '.') . 'đ</span>' : '') .
                                                                                '</div>'
                                                                            );
                                                                        }
                                                                        return new \Illuminate\Support\HtmlString('<span class="text-gray-400 italic text-xs">Chọn ngày để tính đêm</span>');
                                                                    }),
                                                            ]),

                                                        Hidden::make('name'),

                                                        Hidden::make('product_style')
                                                            ->default(1),

                                                        Hidden::make('price_per_night')
                                                            ->default(0),
                                                    ])
                                                    ->columns(2)
                                                    ->label('Danh sách phòng đặt')
                                                    ->collapsible()
                                                    ->addActionLabel('Thêm phòng')
                                                    ->addable(true)
                                                    ->maxItems(20)
                                                    ->minItems(1)
                                                    ->itemLabel(fn($state) => !empty($state['name']) ? ' ' . $state['name'] : 'Phòng mới')
                                                    ->defaultItems(0)
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                                                        self::calculateTotal($get, $set);
                                                    }),


                                                    Repeater::make('orderServices')
    ->relationship('services')
    ->schema([
        Grid::make(3)->schema([
            Select::make('service_id')
                ->label('Dịch vụ')
                ->options(function () {
                    $user  = auth()->user();
                    $query = \Modules\BladeThemeV1\App\Models\AdditionService::where('is_active', 1);

                    if ($user && ! $user->isSuperAdmin()) {
                        $allowedIds = $user->allowedCategoryIds() ?? [];
                        if (empty($allowedIds)) {
                            return [];
                        }
                        $productIds = \Modules\Product\App\Models\Product::whereHas(
                            'categories',
                            fn ($q) => $q->whereIn('categories.id', $allowedIds)
                        )->pluck('id');

                        $query->whereHas('products', fn ($q) => $q->whereIn('products.id', $productIds));
                    }

                    $options = $query->pluck('name', 'id');
                    return $options->isNotEmpty() ? $options : [];
                })
                ->required()
                ->searchable()
                ->preload()
                ->live()
                ->columnSpan(2)
                ->hintIcon('heroicon-o-shopping-bag')
                ->hintColor('primary')
                ->hintIconTooltip('Chọn dịch vụ thêm')
                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                    if ($state) {
                        $service = \Modules\BladeThemeV1\App\Models\AdditionService::find($state);
                        if ($service) {
                            $set('service_name', $service->name);
                            $set('price', $service->price);
                            $qty = (int)($get('quantity') ?? 1);
                            $set('subtotal', $service->price * $qty);
                            self::calculateTotal($get, $set);
                        }
                    }
                }),

            TextInput::make('quantity')
                ->label('Số lượng')
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required()
                ->live(debounce: 300)
                ->hintIcon('heroicon-o-hashtag')
                ->hintColor('primary')
                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                    $price = (float) ($get('price') ?? 0);
                    $qty = max(1, (int) $state);
                    $set('subtotal', $price * $qty);
                    self::calculateTotal($get, $set);
                }),
        ]),

        Grid::make(2)->schema([
            TextInput::make('price')
                ->label('Đơn giá')
                ->numeric()
                ->suffix('đ')
                ->disabled()
                ->dehydrated(true)
                ->hintIcon('heroicon-o-banknotes')
                ->hintColor('gray'),

            TextInput::make('subtotal')
                ->label('Thành tiền')
                ->numeric()
                ->suffix('đ')
                ->disabled()
                ->dehydrated(true)
                ->hintIcon('heroicon-o-calculator')
                ->hintColor('success'),
        ]),

        Hidden::make('service_name'),
    ])
    ->columns(1)
    ->label('Dịch vụ thêm')
    ->collapsible()
    ->addActionLabel('+ Thêm dịch vụ')
    ->defaultItems(0)
    ->live()
    ->afterStateUpdated(function ($state, Get $get, Set $set) {
        self::calculateTotal($get, $set);
    })
    ->itemLabel(fn($state) => !empty($state['service_name'])
        ? $state['service_name'] . (!empty($state['quantity']) ? ' × ' . $state['quantity'] : '') . (!empty($state['subtotal']) ? ' — ' . number_format((float)$state['subtotal'], 0, ',', '.') . 'đ' : '')
        : 'Dịch vụ mới'),
                                            ])
                                            ->columnSpan(5),

                                        Section::make('Tổng thanh toán')
                                            ->description('Chi tiết tính toán chi phí')
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

                                                        // Cộng dịch vụ thêm từ form state
                                                        $servicesFormState = $get('orderServices') ?? [];
                                                        if (is_array($servicesFormState)) {
                                                            $total += collect($servicesFormState)->sum(fn($s) => (float)($s['subtotal'] ?? 0));
                                                        }

                                                        return view('payment::components.total-amount-card', [
                                                            'totalAmount' => $total,
                                                            'items' => $items,
                                                            'record' => $record,
                                                            'servicesFormState' => $servicesFormState,
                                                            'guestCount' => (int)($get('guest_count') ?? 1),
                                                        ]);
                                                    })
                                                    ->dehydrated(true)
                                            ])->columnSpan(2),

                                    ])->columns(7),
                            ]),

                        // TAB 3: THÔNG TIN THANH TOÁN
                        Tabs\Tab::make(__('payment::order.form.fieldset.payment_info'))
                            ->icon('heroicon-m-credit-card')
                            // ->iconColor('warning')
                            ->badge(fn(Get $get) => self::getPaymentInfoBadge($get))
                            ->badgeColor(fn(Get $get) => self::getPaymentInfoBadgeColor($get))
                            ->schema([
                                // TIMELINE THANH TOÁN
                                Section::make('Lịch sử thanh toán')
                                    ->description('Thời điểm tạo đơn và các mốc thanh toán')
                                    ->icon('heroicon-m-clock')
                                    ->iconColor('info')
                                    ->schema([
                                        Placeholder::make('payment_timeline')
                                            ->label('')
                                            ->content(function ($record) {
                                                if (!$record) {
                                                    return new \Illuminate\Support\HtmlString('<p class="text-gray-400 text-sm italic">Chưa có dữ liệu</p>');
                                                }

                                                $isDeposit   = $record->deposit_percent !== null;
                                                $fullAmt     = (int)($record->full_amount ?? $record->amount);
                                                $depositPctF = $isDeposit ? (int)$record->deposit_percent : 0;
                                                if ($isDeposit && $depositPctF > 0) {
                                                    // full_amount với đơn cọc = tiền cọc
                                                    $depositAmt   = $fullAmt;
                                                    $remainingAmt = (int) round($fullAmt * 100 / $depositPctF) - $depositAmt;
                                                } else {
                                                    $depositAmt   = null;
                                                    $remainingAmt = null;
                                                }

                                                $fmt = fn($dt) => $dt ? \Carbon\Carbon::parse($dt)->format('d/m/Y H:i') : null;

                                                // SVG icon helper
                                                $svg = static function (string $type, string $stroke): string {
                                                    $a = "xmlns=\"http://www.w3.org/2000/svg\" width=\"16\" height=\"16\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"{$stroke}\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\"";
                                                    return match ($type) {
                                                        'banknote' => "<svg {$a}><rect x=\"2\" y=\"5\" width=\"20\" height=\"14\" rx=\"2\"/><line x1=\"2\" y1=\"10\" x2=\"22\" y2=\"10\"/></svg>",
                                                        'check'    => "<svg {$a}><path d=\"M22 11.08V12a10 10 0 1 1-5.93-9.14\"/><polyline points=\"22 4 12 14.01 9 11.01\"/></svg>",
                                                        'file'     => "<svg {$a}><path d=\"M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z\"/><polyline points=\"14 2 14 8 20 8\"/></svg>",
                                                        'qr'       => "<svg {$a}><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"/></svg>",
                                                        default    => "<svg {$a}><circle cx=\"12\" cy=\"12\" r=\"10\"/></svg>",
                                                    };
                                                };

                                                if ($isDeposit) {
                                                    $remainingDone = $record->status === 'paid' && $record->deposit_percent !== null;
                                                    $remainingTime = $record->remaining_paid_at ? $fmt($record->remaining_paid_at) : null;
                                                    $qrCreated     = ! empty($record->remaining_checkout_url);
                                                    $payMethod     = $record->remaining_payment_method ?? null;

                                                    if ($remainingDone) {
                                                        if ($payMethod === 'cash') {
                                                            $remainingLabel = 'Admin thu tiền mặt';
                                                        } elseif ($payMethod === 'payos') {
                                                            $remainingLabel = 'Khách thanh toán qua PayOS';
                                                        } else {
                                                            $remainingLabel = $qrCreated ? 'Khách thanh toán qua QR/PayOS' : 'Admin xác nhận thu tiền mặt';
                                                        }
                                                    } else {
                                                        $remainingLabel = 'Chờ thanh toán phần còn lại';
                                                    }

                                                    $steps = [
                                                        [
                                                            'icon_type' => 'banknote',
                                                            'label'     => 'Khách đặt cọc',
                                                            'time'      => $fmt($record->created_at),
                                                            'done'      => true,
                                                            'color'     => 'yellow',
                                                            'sub'       => $depositAmt ? number_format($depositAmt, 0, ',', '.') . 'đ (' . $record->deposit_percent . '%)' : null,
                                                        ],
                                                        [
                                                            'icon_type' => 'qr',
                                                            'label'     => $qrCreated ? 'QR thanh toán đã được tạo' : 'Chưa tạo QR thanh toán',
                                                            'time'      => null,
                                                            'done'      => $qrCreated,
                                                            'color'     => 'yellow',
                                                            'sub'       => null,
                                                        ],
                                                        [
                                                            'icon_type' => $payMethod === 'cash' ? 'banknote' : 'check',
                                                            'label'     => $remainingLabel,
                                                            'time'      => $remainingTime,
                                                            'done'      => $remainingDone,
                                                            'color'     => 'green',
                                                            'sub'       => ($remainingDone && $remainingAmt) ? number_format($remainingAmt, 0, ',', '.') . 'đ' : null,
                                                        ],
                                                    ];
                                                } else {
                                                    $paidAt = $record->status === 'paid' ? $fmt($record->updated_at) : null;
                                                    $steps = [
                                                        [
                                                            'icon_type' => 'file',
                                                            'label'     => 'Khách tạo đơn',
                                                            'time'      => $fmt($record->created_at),
                                                            'done'      => true,
                                                            'color'     => 'blue',
                                                            'sub'       => null,
                                                        ],
                                                        [
                                                            'icon_type' => 'check',
                                                            'label'     => 'Khách thanh toán đầy đủ',
                                                            'time'      => $paidAt,
                                                            'done'      => $record->status === 'paid',
                                                            'color'     => 'green',
                                                            'sub'       => $fullAmt ? number_format($fullAmt, 0, ',', '.') . 'đ' : null,
                                                        ],
                                                    ];
                                                }

                                                $html = '<div class="flex flex-col gap-0">';
                                                foreach ($steps as $i => $step) {
                                                    $isLast   = $i === count($steps) - 1;
                                                    $dotBg    = $step['done']
                                                        ? match($step['color']) {
                                                            'blue'   => '#3b82f6',
                                                            'yellow' => '#f59e0b',
                                                            'green'  => '#22c55e',
                                                            default  => '#6b7280',
                                                        }
                                                        : '#e5e7eb';
                                                    $dotBorder  = $step['done'] ? '' : 'border:2px dashed #9ca3af;';
                                                    $dotStroke  = $step['done'] ? 'white' : '#9ca3af';
                                                    $labelColor = $step['done'] ? '#111827' : '#9ca3af';
                                                    $iconHtml   = $svg($step['icon_type'], $dotStroke);

                                                    $timeHtml = $step['time'] && $step['time'] !== '(Không rõ thời điểm)'
                                                        ? '<span style="font-size:12px;color:#6b7280;">' . $step['time'] . '</span>'
                                                        : ($step['done']
                                                            ? '<span style="font-size:12px;color:#9ca3af;font-style:italic;">Không rõ thời điểm</span>'
                                                            : '<span style="font-size:12px;color:#d1d5db;font-style:italic;">Chưa thực hiện</span>');

                                                    $subHtml = $step['sub']
                                                        ? ' <span style="font-size:12px;background:#f3f4f6;color:#374151;padding:1px 7px;border-radius:999px;font-weight:600;">' . $step['sub'] . '</span>'
                                                        : '';

                                                    $html .= '
                                                        <div style="display:flex;align-items:flex-start;gap:12px;">
                                                            <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
                                                                <div style="width:32px;height:32px;border-radius:50%;background:' . $dotBg . ';' . $dotBorder . 'display:flex;align-items:center;justify-content:center;flex-shrink:0;">' . $iconHtml . '</div>
                                                                ' . (!$isLast ? '<div style="width:2px;background:#e5e7eb;flex:1;min-height:24px;margin:2px 0;"></div>' : '') . '
                                                            </div>
                                                            <div style="padding-bottom:' . (!$isLast ? '12px' : '0') . ';padding-top:4px;">
                                                                <div style="font-weight:600;font-size:14px;color:' . $labelColor . ';">' . $step['label'] . $subHtml . '</div>
                                                                <div style="margin-top:2px;">' . $timeHtml . '</div>
                                                            </div>
                                                        </div>
                                                    ';
                                                }
                                                $html .= '</div>';

                                                return new \Illuminate\Support\HtmlString($html);
                                            }),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),

                                // SECTION: Thanh toán còn lại — chỉ hiện với đơn cọc chưa thanh toán đủ
                                Section::make('Thanh toán còn lại')
                                    ->description('Tạo link QR hoặc xác nhận thu tiền mặt phần còn lại')
                                    ->icon('heroicon-m-qr-code')
                                    ->iconColor('warning')
                                    ->visible(fn ($record) => $record && $record->status === 'deposit' && $record->deposit_percent !== null)
                                    ->schema([
                                        TextInput::make('full_amount')
                                            ->label('Số tiền đã cọc (full_amount)')
                                            ->helperText('Chỉnh sửa để điều chỉnh số tiền cọc đã thu. Số tiền QR = tổng đơn − giá trị này.')
                                            ->numeric()
                                            ->suffix('VNĐ')
                                            ->live()
                                            ->dehydrateStateUsing(fn ($state) => (int) $state),

                                        Placeholder::make('remaining_qr_preview')
                                            ->label('Số tiền còn lại (dự kiến)')
                                            ->content(function ($record, $get) {
                                                if (! $record) return new \Illuminate\Support\HtmlString('');
                                                $orderAmount = (int) $record->amount;
                                                $fullAmount  = (int) ($get('full_amount') ?? $record->full_amount ?? 0);
                                                $remaining   = max(0, $orderAmount - $fullAmount);
                                                $color       = $remaining <= 0 ? '#dc2626' : '#15803d';
                                                return new \Illuminate\Support\HtmlString(
                                                    '<span style="font-size:15px;font-weight:700;color:' . $color . ';">'
                                                    . number_format($remaining, 0, ',', '.') . 'đ</span>'
                                                    . '<span style="font-size:12px;color:#6b7280;margin-left:8px;">= ' . number_format($orderAmount, 0, ',', '.') . ' (tổng đơn) − ' . number_format($fullAmount, 0, ',', '.') . ' (đã cọc)</span>'
                                                );
                                            }),

                                        \Filament\Forms\Components\Actions::make([
                                            \Filament\Forms\Components\Actions\Action::make('tao_qr_con_lai_form')
                                                ->label('Tạo QR còn lại')
                                                ->color('warning')
                                                ->icon('heroicon-o-qr-code')
                                                ->requiresConfirmation()
                                                ->modalHeading('Tạo link QR thanh toán còn lại')
                                                ->modalDescription('Mỗi lần bấm sẽ tạo link PayOS mới. Link cũ (nếu có) sẽ bị thay thế.')
                                                ->modalSubmitActionLabel('Tạo và mở trang QR')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        $remaining = max(0, (int) $record->amount - (int) $record->full_amount);

                                                        if ($remaining <= 0) {
                                                            Notification::make()->warning()->title('Đơn đã được thanh toán đủ, không cần tạo QR')->send();
                                                            return;
                                                        }

                                                        $payos = new PayOS(
                                                            config('payos.client_id'),
                                                            config('payos.api_key'),
                                                            config('payos.checksum_key')
                                                        );

                                                        $orderCode = (int) (intval(substr(strval(microtime(true) * 10000), -6)) . rand(10, 99));
                                                        $expiredAt = now()->addMinutes(30);
                                                        $response  = $payos->createPaymentLink([
                                                            'orderCode'   => $orderCode,
                                                            'amount'      => $remaining,
                                                            'description' => 'Tt con lai - ' . $record->order_code,
                                                            'returnUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_done=1',
                                                            'cancelUrl'   => \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $record->id]) . '?remaining_cancelled=1',
                                                            'buyerName'   => $record->buyer_name ?? '',
                                                            'buyerPhone'  => $record->buyer_phone ?? '',
                                                            'expiredAt'   => $expiredAt->timestamp,
                                                            'items'       => [[
                                                                'name'     => 'Tiền còn lại - ' . ($record->items->first()?->name ?? 'Phòng'),
                                                                'quantity' => 1,
                                                                'price'    => $remaining,
                                                            ]],
                                                        ]);

                                                        $checkoutUrl = $response['checkoutUrl'] ?? null;
                                                        if (! $checkoutUrl) {
                                                            Notification::make()->danger()->title('Không thể tạo link thanh toán')->send();
                                                            return;
                                                        }

                                                        $record->update([
                                                            'remaining_payos_code'   => (string) $orderCode,
                                                            'remaining_checkout_url' => $checkoutUrl,
                                                        ]);

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            [],
                                                            ['Tạo QR' => number_format($remaining, 0, ',', '.') . 'đ', 'Mã PayOS' => (string) $orderCode],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        return redirect()->away($checkoutUrl);

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),

                                            \Filament\Forms\Components\Actions\Action::make('xac_nhan_thu_tien_form')
                                                ->label('Đã thu tiền mặt')
                                                ->color('success')
                                                ->icon('heroicon-o-banknotes')
                                                ->requiresConfirmation()
                                                ->modalHeading('Xác nhận thu tiền mặt')
                                                ->modalDescription('Xác nhận đã thu tiền mặt phần còn lại. Đơn sẽ được cập nhật "Đã thanh toán đầy đủ" và cấp mã truy cập cho khách.')
                                                ->modalSubmitActionLabel('Xác nhận')
                                                ->action(function ($record) {
                                                    if (! $record) return;
                                                    try {
                                                        $record->update([
                                                            'status'                   => 'paid',
                                                            'remaining_paid_at'        => now(),
                                                            'remaining_payment_method' => 'cash',
                                                        ]);

                                                        $record->load('items.product');
                                                        $firstItem    = $record->items->sortBy('checkin_date')->first();
                                                        $checkinDate  = $record->items->min('checkin_date');
                                                        $checkoutDate = $record->items->max('checkout_date');
                                                        $product      = $firstItem?->product;

                                                        if (! $record->hasAccessCode()) {
                                                            app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class)
                                                                ->assignCodeToOrder($record->id, $record->category_id, $checkinDate, $checkoutDate, $product);
                                                        }

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            ['status' => 'deposit', 'remaining_payment_method' => null],
                                                            ['status' => 'paid', 'remaining_payment_method' => 'cash'],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        Notification::make()
                                                            ->success()
                                                            ->title('Đã xác nhận thanh toán và cấp mã truy cập')
                                                            ->send();

                                                    } catch (\Throwable $e) {
                                                        Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
                                                    }
                                                }),
                                        ]),
                                    ])
                                    ->collapsible()
                                    ->collapsed(false),

                                Section::make('Phương thức thanh toán')
                                    ->description('Chọn phương thức thanh toán và trạng thái đơn hàng')
                                    ->icon('heroicon-m-banknotes')
                                    ->iconColor('primary')
                                    ->schema([
                                        Grid::make(3)->schema([
                                            Select::make('payment_method')
                                                ->label('Phương thức thanh toán')
                                                ->placeholder('-- Chọn phương thức --')
                                                ->options([
                                                    'cod' => ('Tiền mặt'),
                                                    'PayOS' => '' . __('payment::order.form.options.payment_method.PayOS'),
                                                ])
                                                ->required()
                                                ->live()
                                                ->dehydrateStateUsing(fn($state) => $state),

                                            Select::make('status')
                                                ->label('Trạng thái thanh toán')
                                                ->placeholder('-- Chọn trạng thái --')
                                                ->options([
                                                    'pending'           => '' . __('payment::order.form.options.status.pending'),
                                                    'paid'              => '' . __('payment::order.form.options.status.paid'),
                                                    'deposit'           => 'Đã đặt cọc',
                                                    'failed'            => '' . __('payment::order.form.options.status.failed'),
                                                    'cancelled_payment' => 'Hủy QR',
                                                ])
                                                ->default('pending')
                                                ->live()
                                                ->dehydrateStateUsing(fn($state) => $state)
                                                ->afterStateUpdated(function ($state, $record, $old) {
                                                    if ($record) {
                                                        $prevStatus = $record->status;
                                                        $record->update(['status' => $state]);

                                                        AuditLogger::log(
                                                            'update', 'Order', $record,
                                                            ['status' => $prevStatus],
                                                            ['status' => $state],
                                                            'Đơn #' . $record->order_code
                                                        );

                                                        if ($state === 'paid' && !$record->hasAccessCode()) {
                                                            try {
                                                                $record->load('items');
                                                                $firstItem = $record->items->sortBy('checkin_date')->first();
                                                                $checkinDate  = $record->items->min('checkin_date');
                                                                $checkoutDate = $record->items->max('checkout_date');
                                                                $product      = $firstItem?->product;

                                                                /** @var \Modules\BladeThemeV1\Services\AccessCode\AccessCodeService $service */
                                                                $service = app(\Modules\BladeThemeV1\Services\AccessCode\AccessCodeService::class);
                                                                $service->assignCodeToOrder(
                                                                    $record->id,
                                                                    $record->category_id,
                                                                    $checkinDate,
                                                                    $checkoutDate,
                                                                    $product,
                                                                );

                                                                \Filament\Notifications\Notification::make()
                                                                    ->title('Đã gán mã cổng thành công')
                                                                    ->success()
                                                                    ->send();
                                                            } catch (\Exception $e) {
                                                                \Filament\Notifications\Notification::make()
                                                                    ->title('Không thể gán mã cổng')
                                                                    ->body($e->getMessage())
                                                                    ->warning()
                                                                    ->send();
                                                            }
                                                        }
                                                    }
                                                }),

                                            Hidden::make('amount')
                                                ->dehydrated(true),
                                        ]),

                                        TextInput::make('money_deposit')
                                            ->label('Số tiền đã đặt cọc')
                                            ->placeholder('VD: 500000')
                                            ->suffix('VNĐ')
                                            ->numeric()
                                            ->minValue(0)
                                            ->hintIcon('heroicon-o-banknotes')
                                            ->hintColor('warning')
                                            ->hintIconTooltip('Số tiền khách đã đặt cọc thực tế')
                                            ->visible(fn (Get $get) => $get('status') === 'deposit')
                                            ->live(onBlur: true),

                                        Textarea::make('note_for_admin')
                                            ->label('Thông tin người dùng CCCD')
                                            ->placeholder('Thông tin người dùng CCCD')
                                            ->helperText(__('payment::order.form.helper_text.note_for_admin'))
                                            ->columnSpanFull()
                                            ->nullable()
                                            ->rows(5)
                                            ->maxLength(3000),
                                    ])
                                    ->columns(3),

                            ]),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString()
                    ->activeTab(1)
            ]);
    }

    // Helper methods for badges
    private static function getBuyerInfoBadge(Get $get): ?string
    {
        $name = $get('buyer_name');
        $phone = $get('buyer_phone');

        if ($name && $phone) {
            return 'Hoàn thành';
        } elseif ($name || $phone) {
            return 'Chưa đầy đủ';
        }

        return 'Chưa điền';
    }

    private static function getBuyerInfoBadgeColor(Get $get): string
    {
        $name = $get('buyer_name');
        $phone = $get('buyer_phone');

        if ($name && $phone) {
            return 'success';
        } elseif ($name || $phone) {
            return 'warning';
        }

        return 'danger';
    }

    private static function getOrderInfoBadge(Get $get): ?string
    {
        $items = $get('orderItems');

        if (is_array($items) && count($items) > 0) {
            $hasProduct = false;
            $hasDate = false;

            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $hasProduct = true;
                }
                if (!empty($item['checkin_date']) && !empty($item['checkout_date'])) {
                    $hasDate = true;
                }
            }

            if ($hasProduct && $hasDate) {
                return 'Hoàn thành';
            } elseif ($hasProduct || $hasDate) {
                return 'Chưa đầy đủ';
            }
        }

        return 'Chưa có phòng';
    }

    private static function getOrderInfoBadgeColor(Get $get): string
    {
        $items = $get('orderItems');

        if (is_array($items) && count($items) > 0) {
            $hasProduct = false;
            $hasDate = false;

            foreach ($items as $item) {
                if (!empty($item['product_id'])) {
                    $hasProduct = true;
                }
                if (!empty($item['checkin_date']) && !empty($item['checkout_date'])) {
                    $hasDate = true;
                }
            }

            if ($hasProduct && $hasDate) {
                return 'success';
            } elseif ($hasProduct || $hasDate) {
                return 'warning';
            }
        }

        return 'danger';
    }

    private static function getPaymentInfoBadge(Get $get): ?string
    {
        $method = $get('payment_method');
        $status = $get('status');

        if ($method && $status) {
            return match ($status) {
                'paid' => 'Đã thanh toán',
                'pending' => 'Chờ thanh toán',
                'failed' => 'Hủy thanh toán',
                default => 'Chưa xác định'
            };
        }

        return 'Chưa cấu hình';
    }

    private static function getPaymentInfoBadgeColor(Get $get): string
    {
        $status = $get('status');

        return match ($status) {
            'paid' => 'success',
            'pending' => 'warning',
            'failed' => 'danger',
            default => 'gray'
        };
    }

    private static function calculateTotal(Get $get, Set $set): void
    {
        $items = $get('../../orderItems') ?? $get('orderItems') ?? [];

        // Số khách cấp order (matching API: tính phụ thu 1 lần, không per-item)
        $orderGuestCount = (int)($get('../../guest_count') ?? $get('guest_count') ?? 1);

        $itemsByProduct = [];
        $noProductItems = [];
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId) {
                $itemsByProduct[$productId][] = $item;
            } else {
                $noProductItems[] = $item;
            }
        }

        $total = 0;

        foreach ($itemsByProduct as $productId => $productItems) {
            $product = \Modules\Product\App\Models\Product::find($productId);
            $cfg     = $product ? ($product->room_config ?? []) : [];
            $maxFree = (int)($cfg['max_free_guests'] ?? 2);
            $feeEach = (int)($cfg['extra_guest_fee'] ?? 0);

            $slotCount = count($productItems);
            $bulkDiscountPct = 0;

            if ($product && $slotCount >= 2) {
                $rules = $product->bulk_discount_rules ?? [];
                usort($rules, fn($a, $b) => (int)($b['slots'] ?? 0) - (int)($a['slots'] ?? 0));
                foreach ($rules as $rule) {
                    if ($slotCount >= (int)($rule['slots'] ?? 0)) {
                        $bulkDiscountPct = (float)($rule['discount'] ?? 0);
                        break;
                    }
                }
            }

            foreach ($productItems as $item) {
                $basePrice = (float)($item['price'] ?? 0);
                if ($bulkDiscountPct > 0) {
                    $basePrice = round($basePrice * (1 - $bulkDiscountPct / 100));
                }
                $total += $basePrice;
            }

            // Phụ thu khách: tính 1 lần per product group, dùng guest_count cấp order (matching API)
            if ($feeEach > 0 && $orderGuestCount > $maxFree) {
                $total += ($orderGuestCount - $maxFree) * $feeEach;
            }
        }

        foreach ($noProductItems as $item) {
            $total += (float)($item['price'] ?? 0);
        }

        $services = $get('../../orderServices') ?? $get('orderServices') ?? [];
        $serviceTotal = collect($services)->sum(fn($s) => (float)($s['subtotal'] ?? 0));
        $total += $serviceTotal;

        $set('../../amount', $total);
        $set('amount', $total);
    }
}